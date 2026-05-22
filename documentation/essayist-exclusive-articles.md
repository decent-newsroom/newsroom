# Essayist-exclusive articles

A way to mark individual articles in the local database as **Essayist-exclusive**
so they are only served to logged-in Essayist members (or admins) and never leak
to anonymous or non-member traffic — even if the underlying Nostr event was
projected into the main `article` table via a generic ingestion path.

The flag is a **local serving control**. It does not alter the signed Nostr
event, does not delete the event from `event`, and does not affect what
`strfry-essayist` itself accepts. It only changes what the Symfony app is
willing to surface from its own DB.

## Data model

`article.essayist_exclusive BOOLEAN NOT NULL DEFAULT FALSE`

A partial index on `(essayist_exclusive) WHERE essayist_exclusive = TRUE` keeps
admin/audit queries on the (very small) flagged subset cheap without bloating
the index on every regular write.

Migration: `migrations/Version20260523120000.php`.

## Auto-flagging on ingestion (NIP-70)

`ArticleFactory::createFromLongFormContentEvent()` inspects the event tags
during projection. When a `["-"]` tag (NIP-70 *protected* marker) is present,
the resulting `Article` row is tentatively flagged as exclusive.
`ArticleEventProjector::projectArticleFromEvent()` then cross-checks the
article's author: the flag is only retained when the author holds one of

- `ROLE_ESSAYIST_MEMBER`
- `ROLE_ESSAYIST_EARLY_BIRD`
- `ROLE_ADMIN`

If the author has no local `User` row or does not hold one of these roles,
the projector clears `essayistExclusive` before persisting. The NIP-70 `-`
tag has broader semantics on Nostr — any author can use it to ask
cooperating relays not to re-broadcast their event for any reason — so we
must not silently disappear unrelated authors' protected events from our
public feeds just because they share a tag with our exclusives.

This is the same `["-"]` tag the editor's **Publish ONLY to Essayist**
toggle adds client-side via `nostr_publish_controller.js`. The toggle is
itself only visible to members and admins, so the round-trip works
end-to-end with no manual ops step:

1. Member author publishes with "Publish ONLY to Essayist" → event carries `["-"]`.
2. The event reaches strfry-essayist (and, if the user also chose ALSO-mode,
   the local strfry); both ingestion paths project through `ArticleFactory`.
3. The projector confirms the author is a member and persists the row with
   `essayist_exclusive = true`.
4. Public listings and the single-article view filter / gate it automatically.

Re-publishing a new revision **without** the `["-"]` tag clears the flag on the
new row (older revisions are removed by the NIP-01 ordering guard in
`ArticleEventProjector`), so an author can "unprotect" content simply by
re-publishing it. Conversely, adding the tag in a later revision flips the
flag on going forward — provided the author is still a member at ingest time.

## Repository contract

Every public listing/search method on `ArticleRepository` takes an
`bool $includeEssayistExclusive = false` parameter:

| Method                          | Default behaviour                                  |
|---------------------------------|----------------------------------------------------|
| `findLatestArticles`            | exclude exclusives                                 |
| `searchByQuery`                 | exclude exclusives                                 |
| `findByTopics`                  | exclude exclusives                                 |
| `findLatestByPubkeys`           | exclude exclusives                                 |
| `findByPubkey`                  | exclude exclusives                                 |
| `advancedSearch` / `…WithTags`  | exclude exclusives                                 |
| `findArticlesWithComments`      | exclude exclusives                                 |

A member-only feed that wants to *include* exclusives must pass `true`
explicitly. Today the Essayist surfaces read from `strfry-essayist` directly
(see `EssayistFeedService`), so no caller passes `true` yet — the opt-in exists
purely so future DB-backed member feeds cannot accidentally drop their
exclusives.

`findOneBy(['slug' => …, 'pubkey' => …])` and the other Doctrine magic finders
do **not** filter automatically: the single-article view in
`Reader/ArticleController` gates the result by calling
`Article::isEssayistExclusive()` and falling through to the standard
"article not found" template when the viewer is neither a member, early bird,
nor admin. We return a not-found response rather than 403 so the existence of
the exclusive is not disclosed.

## CLI

```bash
# Flag every revision of a coordinate (operates on pubkey + d-tag).
docker compose exec php bin/console essayist:mark-exclusive <npub> <slug>

# Same, using a hex pubkey:
docker compose exec php bin/console essayist:mark-exclusive <hexpubkey> <slug>

# Same, using a full NIP-01 coordinate:
docker compose exec php bin/console essayist:mark-exclusive 30023:<hexpubkey>:<slug>

# Clear the flag.
docker compose exec php bin/console essayist:mark-exclusive <npub> <slug> --unmark
```

Behind the scenes the command calls
`ArticleRepository::setEssayistExclusiveByCoordinate($pubkey, $slug, $exclusive)`
which issues a single bulk `UPDATE` so older revisions (still present in the
table for audit/history) inherit the flag. New revisions re-projected from the
relay later default to `false` — re-run the command after a new revision lands
if the flag must persist.

## Viewer gating

`viewerCanSeeEssayistExclusive()` on `ArticleController` returns `true` for
viewers that hold any of:

- `ROLE_ADMIN`
- `ROLE_ESSAYIST_MEMBER`
- `ROLE_ESSAYIST_EARLY_BIRD`

`ROLE_ESSAYIST_CANDIDATE` is intentionally **not** included — pending applicants
should not yet receive exclusive content.

## Non-goals

- The flag is one-directional: it gates serving, not ingestion. Events still
  land in `event` / `article` as usual; we just refuse to render them to the
  wrong audience.
- It is not a replacement for `HiddenCoordinate` (operator-side hard hide) or
  NIP-09 deletions (author-side). Use those when the goal is to drop content
  entirely.
- Outbound publishing is unaffected. If you want an event to never leave the
  Essayist relay, use the editor's "Publish ONLY to Essayist" toggle (it adds
  the NIP-70 `["-"]` tag and skips the local relay mirror).

