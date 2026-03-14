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

## Lessons Learned

- **Comment fetching refactoring**: Comments were originally fetched inline during page load, causing slow renders. Moved to async via Messenger for non-blocking display.
- **Database persistence**: Comments must be persisted to survive relay unavailability. The `Event` entity stores raw comment events alongside articles.

