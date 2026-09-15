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

## Auto-flagging on ingestion (NIP-70 + source relay)

The NIP-70 `["-"]` (protected) tag on its own is **not** enough to flag an
article as Essayist-exclusive. NIP-70 is a generic "please do not
re-broadcast" marker that any author on any relay can use for any reason
— privacy, draft circulation, unfinished work, whatever. Treating the
tag alone as our exclusive marker would shadow-hide unrelated authors'
protected events that happen to arrive via the local strfry router (which
pulls kind 30023 from public relays like `nos.lol`, `relay.damus.io`,
etc.) or via NIP-65 outbox fetches. That would silently censor work that
has nothing to do with Essayist.

The actual discriminator is **NIP-70 tag AND source relay**:

> An article is auto-flagged `essayist_exclusive = true` only when it is
> received from `strfry-essayist` (the members-only relay) AND carries
> the `["-"]` tag.

Mechanically:

- `ArticleFactory::createFromLongFormContentEvent()` is pure event-to-entity
  mapping. It does not look at the `-` tag at all; it has no idea which
  relay the event came from and intentionally stays generic.
- `ArticleEventProjector::projectArticleFromEvent()` accepts a third
  argument `bool $markEssayistExclusive = false`. When `true`, the
  persisted row is flagged. The caller is responsible for deciding
  whether the source-relay context justifies passing `true`.
- The dedicated `essayist:subscribe-relay` worker (the only ingestion
  path that talks to `strfry-essayist`) sets the argument to the boolean
  "does this event carry the `-` tag?" Every other ingestion path —
  local strfry subscriber, gateway persistence, author content fetches,
  backfill, RSS importer, API fetch, embed prefetch, sync article fetch
  — calls the projector with the default `false`.

What this gives us, case by case:

| Scenario | Source relay | `-` tag | Flagged? |
|---|---|---|---|
| External client publishes to `wss://essayist.…` with the `-` tag (uses our exclusive feature) | `strfry-essayist` | yes | **yes** |
| External client publishes to `wss://essayist.…` without the `-` tag (broadcasts publicly via Essayist too) | `strfry-essayist` | no | no |
| Author tags `-` for unrelated NIP-70 reasons on a public relay; local strfry router pulls it | local strfry | yes | no |
| Editor "Publish ONLY to Essayist" — JS adds `-`, server publishes to strfry-essayist only | `strfry-essayist` | yes | **yes** (subscriber); the controller also sets the flag explicitly when the author actually holds the gating role, so the in-app path is authoritative at write time |
| Editor "Also publish to Essayist" — published to outbox + strfry-essayist, no `-` tag | both | no | no |

Re-publishing a new revision **without** the `-` tag clears the flag on
the new row (older revisions are removed by the NIP-01 ordering guard in
`ArticleEventProjector`), so an author can "unprotect" content simply by
re-publishing it. Conversely, adding the tag in a later revision and
sending it to the Essayist relay flips the flag on going forward.

## Ingestion: hydrating from strfry-essayist

`SubscribeEssayistRelayCommand` (`essayist:subscribe-relay`) runs as part
of the worker-relay process pool and maintains a long-lived WebSocket
subscription to the internal `ESSAYIST_RELAY_INTERNAL_URL` (default
`ws://strfry-essayist:7779`). It filters on kinds `30023` (longform) and
`30024` (draft), inspects each incoming event for the NIP-70 `["-"]`
tag, and pipes the event through `ArticleEventProjector` with
`markEssayistExclusive` set to the result of that check.

The worker is auto-spawned by `RunRelayWorkersCommand` whenever the env
var is non-empty; when the `essayist` compose profile is off the var is
empty and the spawn step is skipped. When the var is set but the relay
is unreachable (e.g. profile booted up after the worker, container
restart) the command sleeps for 30 seconds and reconnects, so the two
services can come up in any order.

Because the PHP container reaches `strfry-essayist` over the internal
compose network — not via Caddy or `essayist-gateway` — the NIP-42
membership check is sidestepped for the subscriber, exactly the same
way `ArticleBroadcastController` sidesteps it for outbound publishes
from logged-in members.

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
explicitly. The current Essayist surfaces (`/essayist/feed`, `/essayist/home`,
the "Latest from Essayist" widget) read from `strfry-essayist` directly via
`EssayistFeedService`, but DB-backed member feeds — and any future
member-only search — can opt in via this parameter so their exclusives are
not accidentally dropped.

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

