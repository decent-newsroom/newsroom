# Backfilling articles from the local relay

After a DB cleanup or a past projector bug the `article` table may have gaps
while the underlying Nostr events (kind:30023 longform, and optionally
kind:30040 / 30041 publication index + content) are still present on the local
strfry relay. The `articles:backfill-local` command re-ingests those events into
the database idempotently.

## When to use it

Use this command when:

- A bulk cleanup or migration dropped article rows while the events are still on strfry.
- You notice missing articles in the middle of an author's history (the live
  hydration worker `articles:subscribe-local-relay` can't help here — it resumes
  from the newest stored `created_at` and never looks backwards).
- You want to re-project events after a change in `ArticleEventProjector` /
  `ArticleFactory` so previously-skipped events get another chance.

It is **not** needed for fresh events flowing in from the relay — those are
handled in real time by `articles:subscribe-local-relay`.

## Usage

Run inside the `php` container:

```bash
# Backfill every longform article currently on the local relay
docker compose exec php bin/console articles:backfill-local

# Last 90 days only
docker compose exec php bin/console articles:backfill-local --since="-90 days"

# Include publication indices + content
docker compose exec php bin/console articles:backfill-local --kinds=30023,30040,30041

# Count how many events are missing from the DB without writing
docker compose exec php bin/console articles:backfill-local --dry-run

# Bounded window
docker compose exec php bin/console articles:backfill-local \
  --since="2025-01-01" --until="2025-06-30" --limit=5000
```

## Options

| Option           | Default | Notes |
|------------------|---------|-------|
| `--kinds`        | `30023` | Comma-separated event kinds. Only kind:30023 is mapped to the `Article` entity; other kinds flow through the projector's generic path. |
| `--since`        | —       | Unix timestamp or strtotime expression (`-90 days`, `2025-01-01`, …). |
| `--until`        | —       | Unix timestamp or strtotime expression. |
| `--limit`        | —       | Per-filter `limit` sent to the relay. |
| `--idle-timeout` | `30`    | Seconds of relay silence after which the command gives up waiting for EOSE. |
| `--dry-run`      | off     | Report counts and per-event "would ingest" lines, do not persist. |

## Semantics

- **Idempotent.** `ArticleEventProjector::projectArticleFromEvent` checks
  `Article.event_id` first and returns early on match, so running the command
  repeatedly converges to a stable result.
- **One-shot.** The command sends a single `REQ`, drains every stored event,
  and exits on `EOSE` (or the idle timeout). It does not stay connected for
  live events — that is the job of `articles:subscribe-local-relay`.
- **Local only.** It targets `NOSTR_DEFAULT_RELAY` (the in-cluster strfry),
  not the user's NIP-65 read relays or the public content relays. If the
  event isn't on strfry, this command won't fetch it — use
  `articles:get <from> <to>` for that (which queries the configured content
  relay set).
- **Graph tables.** Unlike `ArticleFetchService::ingestRange()`, this command
  routes through `ArticleEventProjector`, which already calls
  `EventIngestionListener::processRawEvent()`, so `parsed_reference` /
  `current_record` stay in sync without a separate
  `graph:backfill-current-records` pass.

## Output

```
 Relay        : ws://strfry:7777
 Kinds        : 30023
 Since        : — beginning of time —
 Until        : — now —
 Limit        : none
 Mode         : WRITE

  ✓ ingested f7a1c8…  (kind 30023)
  ✓ ingested 2bd03e…  (kind 30023)
  processed 100 events (present: 92, ingested: 8, failed: 0)
  …

 [OK] Backfill complete in 12.4s

 ------- ---------------- ---------- --------- --------
  Events  Already present  Ingested   Skipped   Failed
 ------- ---------------- ---------- --------- --------
  1 842    1 789            51         2         0
 ------- ---------------- ---------- --------- --------
```

Exit code is non-zero only when at least one event failed to project (invalid
argument exceptions — e.g. unsupported kind, malformed tag — count as
"skipped", not "failed").

## Related commands

- `articles:subscribe-local-relay` — long-lived daemon that projects new events as they arrive; resumes from `max(created_at)`, so it cannot backfill gaps.
- `articles:get <from> <to>` — fetches longform from the configured content relays (not just the local relay) within a time window.
- `articles:deduplicate` — collapses duplicate rows by `(pubkey, slug, kind)`; run after a large backfill if you suspect duplicates.
- `graph:backfill-current-records` — only needed if the article ingest path bypassed the projector (which this command does not).

