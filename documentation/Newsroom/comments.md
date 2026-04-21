# Comments

## Overview

Comments use Nostr kind 1111 events. They are fetched from relays, persisted to the database, and displayed on article pages and the generic event page (`/e/{nevent}`) via the `Comments` Twig Live Component.

## Architecture

| Component | File |
|-----------|------|
| Twig component | `src/Twig/Components/Organisms/Comments.php` |
| Comment form | `src/Twig/Components/Organisms/CommentForm.php` |
| Projector | `src/Service/CommentEventProjector.php` |
| Fetch handler | `src/MessageHandler/FetchCommentsHandler.php` |
| Stimulus controller | `assets/controllers/nostr/nostr_comment_controller.js` |
| Mercure updates | `assets/controllers/content/comments_mercure_controller.js` |

## Flow

1. Article page loads → `Comments` component fetches from database
2. `FetchCommentsMessage` dispatched async to fetch fresh comments from relays
3. New comments persisted via `CommentEventProjector`
4. Real-time updates pushed to browser via Mercure SSE

## NIP-22 Tag Handling

The `Comments` component parses NIP-22 tags with correct case semantics:

| Tag | Case | Meaning | Usage |
|-----|------|---------|-------|
| `K` | upper | Root event kind | Always present (e.g. `30023` for articles) |
| `k` | lower | Parent item kind | `1111` when replying to another comment |
| `E`/`A`/`I` | upper | Root scope reference | Points to the article/event being commented on |
| `e`/`a`/`i` | lower | Parent item reference | Points to the parent comment (for threaded replies) |
| `P` | upper | Root author pubkey | The article author |
| `p` | lower | Parent author pubkey | The author of the comment being replied to |

### Reply metadata (threaded comments)

When `k` = `1111` (reply to a comment), the component:
1. Resolves lowercase `p`-tag pubkeys to display names via Redis profile cache
2. Shows "Replying to Name1, Name2" above the comment body
3. Looks up the parent comment via lowercase `e`-tag and displays a one-line preview

Top-level comments (where `k` = the article kind) do not show reply metadata since the parent is the article itself.

## Generic event page support

The generic event page at `/e/{nevent}` renders the same comment UI for any event kind, not just longform articles. Two modes:

| Parent type | Example kinds | Root tags | Parent tags | `current` / comment key |
|-------------|---------------|-----------|-------------|-------------------------|
| Addressable (30000–39999 with `d` tag) | 30023 articles, 30040 publications, 39089 follow packs, 1450 tabular data (when addressable), 10015 interests | `A`, `K`, `P` | `a`, `k`, `p` | `kind:pubkey:d-tag` coordinate |
| Non-addressable | 1 notes, 20 pictures, 21/22 videos, 34235/34236 legacy video, generic unknown | `E`, `K`, `P` | `e`, `k`, `p` | 64-hex event id |

Gating: the UI is suppressed for kinds that already have dedicated flows and normally redirect before the event template is reached (30023/30024 articles, 30004–30006 curation sets, 42 chat).

Server-side branching:
- `SocialEventService::getComments($ref, $since, $authorPubkey)` detects coordinate vs event id (contains `:` → coordinate; 64-hex → event id) and filters on `#A` or `#E` respectively. For non-addressable parents, relays are resolved from the optional `$authorPubkey` argument, falling back to the default relay pool when no pubkey hint is available.
- `FetchCommentsMessage($coordinate, $authorPubkey = null)` carries the pubkey hint through to the handler.
- `CommentController::publish` accepts either an `A` tag (addressable root) or an `E` + `P` tag pair (non-addressable root) to resolve commenter + parent-author relays for publishing.

The database layer (`EventRepository::findCommentsByCoordinate`) already matches on any `tag[1]` value regardless of the tag key, so both coordinates and event ids work unchanged.

## Lessons Learned

- **Comment fetching refactoring**: Comments were originally fetched inline during page load, causing slow renders. Moved to async via Messenger for non-blocking display.
- **Database persistence**: Comments must be persisted to survive relay unavailability. The `Event` entity stores raw comment events alongside articles.

