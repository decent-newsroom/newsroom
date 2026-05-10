# Article revision hardening (v0.0.38)

This note documents the second-pass hardening of the parameterized-replaceable
event pipeline for `kind:30023` (longform) and `kind:30024` (longform-draft)
articles. The goal: keep at most one row per `(pubkey, kind, d-tag)` coordinate
in Postgres at all times, with the Elasticsearch `articles` index synchronised
in lockstep, regardless of which ingestion path is in use.

## NIP-01 replaceability primer

> Per [NIP-01](https://github.com/nostr-protocol/nips/blob/master/01.md):
> a parameterized-replaceable event (kinds `30000-39999`) is uniquely
> addressed by `(kind, pubkey, d-tag)`. Of the events sharing that address,
> the one with the highest `created_at` is the canonical revision; on equal
> `created_at`, the lexicographically lower `event id` wins.

The DB row in `article` is the projection of the event; only the canonical
revision should ever be present in the table.

## Ingestion paths

There are five paths that can create or replace an `Article` row:

| Path | Trigger | Now goes through |
|------|---------|------------------|
| Strfry subscription worker | `app:run-relay-workers` | `ArticleEventProjector::projectArticleFromEvent` |
| Author refresh | `FetchAuthorContentHandler` | `ArticleEventProjector::projectArticleFromEvent` *(was direct persist)* |
| Editor publish | `POST /api/article/publish` | direct persist + `ReplaceableEventCleanupService::removeOlderArticleRevisions` *(was direct persist + nothing)* |
| Sync article fetch | `ArticleController` (`/a/{naddr}`) | `ArticleEventProjector::projectArticleFromEvent` |
| Backfill / RSS / API fetch / embed prefetch | various | `ArticleEventProjector::projectArticleFromEvent` |

Every path either runs through the projector (which contains the NIP-01
guard) or through the cleanup service (which is now ORM-aware).

## The two old failure modes

### 1. Pre-persist guard was missing

`ArticleEventProjector` only checked for an existing row by **`event_id`**.
A late-arriving older revision (e.g. an RSS importer reaching back in time,
`articles:backfill-local` against a relay with older history, or a slow
public relay finally sending a revision the writer published a week ago)
has a *different* `event_id` from the current revision — so the
duplicate check passed, the older revision was happily persisted, and the
cleanup pass that ran after `persist()` found no rows to delete (it only
deletes rows older than the just-persisted one). Result: two rows for the
same coordinate, indefinitely.

The new projector pulls every row for the coordinate, applies NIP-01
ordering, and either:

- silently drops the incoming event if a newer revision already exists, or
- removes the older existing rows **before** persisting the new one.

### 2. Bulk DQL DELETE bypassed the FOS Elastica listener

`ReplaceableEventCleanupService::removeOlderArticleRevisions` used
`$qb->delete(Article::class)` — a Doctrine DQL bulk delete. DQL bulk
operations bypass the unit-of-work and **do not fire** `postRemove`
lifecycle events. The FOS Elastica Doctrine listener subscribes to
`postRemove` to evict deleted entities from the index. Net effect: rows
were gone from Postgres, but their docs lingered in the
`articles` index until someone ran `fos:elastica:populate --reset`.
This was the root cause of long-tail authors having ten or more
ES docs returned for what was clearly a single article in Postgres.

The cleanup service now does:

```php
$stale = $qb->select('a')->from(Article::class, 'a')...->getQuery()->getResult();
foreach ($stale as $oldArticle) {
    $em->remove($oldArticle);
}
$em->flush();
```

Each `remove()` call enters the unit of work, `flush()` issues an SQL
`DELETE` per row, and `postRemove` fires for each — so the FOS Elastica
listener evicts each ES doc as the row leaves the DB.

## EditorController publish path

The editor's publish endpoint (`POST /api/article/publish`) now calls
`ReplaceableEventCleanupService::removeOlderArticleRevisions(...)` after
persisting the new revision. Previously this path had no cleanup at all —
every save by a writer left the prior revision alive in both Postgres and
ES. For writers who iterate frequently this was the dominant source of
revision overflow.

## Author refresh path

`FetchAuthorContentHandler::saveArticle()` was deleted. It had two latent
bugs on top of the missing cleanup:

- It checked `eventRepository->findOneBy(['eventId' => ...])`, but `Event`
  has no `eventId` column (the Nostr id is its primary key column, named
  `id`). The query never matched, so the idempotency check was effectively
  a no-op.
- It called `entityManager->persist($article)` and `flush()` without any
  ordering check.

The author-refresh path now invokes the projector directly:

```php
$this->articleEventProjector->projectArticleFromEvent($event, 'author-refresh');
```

so it inherits the NIP-01 guard, the ORM-aware cleanup, and the graph-table
sync (`EventIngestionListener::processRawEvent`) for free.

## One-shot cleanup of historical overflow

For databases that accumulated revisions before this hardening landed,
run:

```bash
docker compose exec php bin/console articles:purge-revisions --dry-run
docker compose exec php bin/console articles:purge-revisions --batch-size=200 --sleep-ms=100
```

This command:

1. Scans `article` for coordinates `(pubkey, kind, slug)` with more than
   one row using a single Postgres aggregation.
2. For each affected coordinate, loads every revision through the ORM,
   applies NIP-01 ordering (`createdAt DESC, eventId ASC`), keeps the
   winner, and calls `EntityManager::remove()` on each loser.
3. Flushes per coordinate so the FOS Elastica listener fires `postRemove`
   for each deleted row — Postgres and ES converge in a single pass.

Unlike `articles:deduplicate` (which only flags rows `DO_NOT_INDEX` so a
subsequent `db:cleanup` can remove them through the ORM), this command
deletes the rows directly. Use `articles:deduplicate` + `db:cleanup` only
if you specifically need the two-step flagging workflow.

Options:

- `--pubkey HEX` — only purge revisions for one author.
- `--batch-size N` (default `200`) — coordinates per batch before the EM is `clear()`-ed.
- `--sleep-ms N` (default `100`) — pause between batches.
- `--max-batches N` (default `0` = unlimited) — cap work per invocation.
- `--dry-run` — count and exit.

## Concurrency

The projector catches `Doctrine\DBAL\Exception\UniqueConstraintViolationException`
during `flush()` and treats it as a concurrent ingestion of the same event
by another worker. The other worker's row is functionally identical (same
event id, same content, same coordinate), so yielding silently is correct.
A `resetManager()` call follows so the EM is usable for the next message.

## What is NOT changed

- A unique index on `(pubkey, kind, slug)` was considered and deferred.
  Adding one safely requires running `articles:purge-revisions` first
  on every database it lands on, and would still need the projector's
  pre-persist remove-then-persist ordering (so the unique key is never
  violated mid-transaction). With the runtime hardening in place, the
  steady-state guarantee is already strong enough; the constraint can be
  added later as a defensive belt-and-braces measure.
- `articles:deduplicate` is unchanged. It is still useful for operators who
  prefer the two-stage flagging workflow (flag → audit → `db:cleanup`).
- The `Event` entity's `removeOlderEventVersions` cleanup still uses bulk
  DQL `DELETE` because `Event` is not indexed by FOS Elastica — there is
  no listener that needs to fire.

