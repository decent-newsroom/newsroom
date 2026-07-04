# Card bookmark shortcut

## Overview

Article cards now include a dedicated bookmark shortcut button.

The shortcut publishes kind `10003` bookmark updates without requiring a page reload between actions.

The article actions dropdown bookmark action now uses the same IndexedDB-backed publish flow.

## Frontend flow

- Template: `templates/components/Molecules/Card.html.twig`
- Stimulus controller: `assets/controllers/ui/card_bookmark_controller.js`
- Dropdown controller: `assets/controllers/ui/article_actions_dropdown_controller.js`
- Bookmark button is rendered for logged-in users only.
- Coordinate format used for bookmarks: `kind:pubkey:slug`

## Reliability strategy

The Stimulus controller keeps the latest bookmark state in IndexedDB:

- IndexedDB database: `newsroom-bookmarks`
- Store: `bookmark-events` (keyed by signer pubkey)
- Saved state includes tags, last signed event, publish status, retry counters, and last success timestamp.

When a user toggles a bookmark:

1. The controller starts from the last known bookmark snapshot in IndexedDB.
2. It adds/removes the target `a` tag coordinate locally.
3. It signs a fresh kind `10003` event.
4. It persists the signed event as `pending` in IndexedDB.
5. It publishes to `POST /api/bookmarks/publish`.

If publish fails, the signed event remains in IndexedDB and is retried with exponential backoff (capped), so retries remain idempotent and resume from the same stored signed payload.

## APIs used

- `GET /api/bookmarks/current` (`api_bookmarks_current`)
- `POST /api/bookmarks/publish` (`api_bookmarks_publish`)

## Styling

Card bookmark button styling lives in:

- `assets/styles/03-components/card.css`

