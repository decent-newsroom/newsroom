# Comments

## Overview

Comments use Nostr kind 1111 events. They are fetched from relays, persisted to the database, and displayed on article pages via the `Comments` Twig Live Component.

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

## Lessons Learned

- **Comment fetching refactoring**: Comments were originally fetched inline during page load, causing slow renders. Moved to async via Messenger for non-blocking display.
- **Database persistence**: Comments must be persisted to survive relay unavailability. The `Event` entity stores raw comment events alongside articles.

