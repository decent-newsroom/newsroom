# Highlights

## Overview

Highlights are Nostr kind 9802 events (NIP-84) — user-selected excerpts from articles. They are fetched from relays, persisted to the database, and displayed on article pages and a dedicated highlights feed. Logged-in users can create highlights by selecting text in an article.

## Architecture

| Component | File |
|-----------|------|
| Entity | `src/Entity/Highlight.php` |
| Service | `src/Service/HighlightService.php` |
| Fetch command | `src/Command/FetchHighlightsCommand.php` |
| Cache command | `src/Command/CacheLatestHighlightsCommand.php` |
| Feed controller | `src/Controller/Reader/HighlightsController.php` |
| Publish controller | `src/Controller/HighlightController.php` |
| Stimulus controller | `assets/controllers/nostr/nostr_highlight_controller.js` |
| Toggle controller | `assets/controllers/ui/highlights_toggle_controller.js` |
| CSS | `assets/styles/04-pages/highlights.css` |

## Display

- **Article page sidebar**: Highlights shown in a collapsible sidebar section with the highlighter's profile
- **Article page mobile**: Highlights shown after comments on narrow screens
- **Inline highlighting**: Existing highlights are marked inline in the article text (toggled via button)
- **Highlights feed**: `/highlights` page shows latest highlights from Redis view store

## Creating Highlights

When a logged-in user selects text in an article:

1. A floating "Highlight" button appears near the selection
2. Clicking it triggers the `nostr--highlight` Stimulus controller
3. The controller builds a kind 9802 event per NIP-84:
   - `.content` = the selected text
   - `a` tag = `30023:<pubkey>:<slug>` (article coordinate)
   - `p` tag = article author pubkey with `author` role
   - `context` tag = the surrounding paragraph text (optional)
4. The event is signed via the user's Nostr signer (NIP-07/NIP-46)
5. The signed event is POSTed to `/highlights/publish`
6. The backend verifies the signature, publishes to the user's + author's relays, and persists locally for immediate display

## Coordinate Consistency

Highlights are stored with an `articleCoordinate` matching the format `30023:<pubkey>:<slug>`. All ingestion paths (cron fetch, async refresh, publish endpoint) extract the coordinate from the event's own `a` tag when available, ensuring consistency regardless of the entry point.

## Cron Schedule

- `*/15 min`: Fetch highlights from relays to database
- `*/30 min`: Cache latest highlights to Redis views

## Lessons Learned

- **Performance**: Highlights fetch was moved to async (Messenger + cron) rather than inline during page load. This eliminated slow article page renders when relay connections were slow.
- **Feature gate removed**: The `HIGHLIGHTS_ENABLED` env flag was removed after the async refactor solved the original performance concern.
- **Coordinate mismatch bug**: Fixed an issue where `FetchHighlightsCommand` stored coordinates from the event's `a` tag, while `RefreshArticleHighlightsHandler` used the controller-supplied coordinate. Both now canonicalise from the event's `a` tag.
- **Multi-relay queries**: Highlights are now fetched from both the local strfry relay and default relays for broader coverage.
