# Bookmarks Feature

## Overview

Bookmarks use NIP-51 kind 10003 events. Kind 10003 is a **replaceable** event (NIP-01, range 10000–19999), meaning only one event per npub should exist — the latest one replaces all previous versions.

Each bookmark entry is stored as a tag in the event:
- `["e", "<event-id>"]` — bookmarks a specific note/event by ID
- `["a", "30023:<pubkey>:<d-tag>"]` — bookmarks a long-form article by coordinate
- `["t", "<hashtag>"]` — bookmarks a hashtag/topic
- `["p", "<pubkey>"]` — bookmarks a user profile

## Bug Fix: Deduplication

### Problem
Kind 10003 events were not being deduplicated in the bookmarks display. If a user had multiple kind 10003 events in the database (e.g., from events ingested before the `GenericEventProjector` deduplication logic was added), all of them would be displayed on the bookmarks page.

### Solution
1. **Display-time deduplication** in `BookmarksController::loadBookmarks()`: after querying bookmark events from the DB, the controller now deduplicates:
   - Kind 10003 (replaceable): keeps only the newest event per pubkey+kind
   - Kinds 30003–30006 (parameterized replaceable): keeps only the newest event per pubkey+kind+dTag
2. **Automatic cleanup**: stale duplicate events detected during deduplication are deleted from the database in the same request.
3. **Ingestion-time deduplication**: the `GenericEventProjector` already handles this for newly ingested events via `removeOlderReplaceableVersions()`.

## Feature: Bookmark Button on Articles

### User Flow
1. User views an article while logged in
2. A "Bookmark" button appears in the article actions bar (alongside Reading List, Zap, Broadcast)
3. On page load, the button fetches the user's current bookmarks from `/api/bookmarks/current`
4. If the article is already bookmarked, the button shows a filled bookmark icon
5. Clicking the button:
   - Gets the user's Nostr signer (NIP-07 extension or NIP-46 remote signer)
   - Builds a new kind 10003 event with all existing tags preserved, plus the article's `a` tag added (or removed if unbookmarking)
   - Signs the event via the signer
   - POSTs the signed event to `/api/bookmarks/publish`
   - The backend persists via `GenericEventProjector` (proper replaceable event dedup) and broadcasts to the user's relays
6. The button toggles visually (filled/unfilled) after successful publish

### Technical Architecture

#### Backend

**API Endpoints** (`BookmarksController`):

| Method | Path | Route Name | Purpose |
|--------|------|------------|---------|
| GET | `/api/bookmarks/current` | `api_bookmarks_current` | Returns the user's current kind 10003 event tags as JSON |
| POST | `/api/bookmarks/publish` | `api_bookmarks_publish` | Receives a signed bookmark/list event, validates ownership, persists, and broadcasts |

**Publish flow**:
1. Validate signed event structure and kind (must be 10003)
2. Verify event signature via the host `NostrEventVerifier` adapter over Innis core
3. Persist via `GenericEventProjector::projectEventFromNostrEvent()` — this handles:
   - Checking for existing newer versions
   - Persisting the new event
   - Deleting older replaceable versions (NIP-01 semantics)
4. Broadcast to user's relays via `NostrClient::publishEvent()`
5. Return relay success/failure summary

#### Frontend

Bookmark toggling is handled by `ui--card-bookmark` on article cards and in
the `ArticleSocialActions` component. Both use the shared IndexedDB snapshot
and signed publish flow described below. The secondary
[article actions dropdown](article-actions-dropdown.md) handles Nostr identifiers
and broadcasting.

The bookmarks page uses `ui--bookmark-list` for removal. It preserves the
current list's non-target tags and content, removes the selected `e`, `a`, `p`,
or `t` tag, asks the user's signer to sign the replacement event, and publishes
it through the same endpoint. Standard kind 10003 removals also update the
shared IndexedDB bookmark snapshot and notify article-card bookmark controls.
Article-coordinate bookmarks rely on the article card's `Bookmarked` shortcut
for removal, so the bookmarks page does not render a second standalone remove
control for `a` tags.
`BookmarkItemResolver` remains a Live Component only for lazy event fetching;
bookmark removal is deliberately not a LiveAction because the server cannot
sign on the user's behalf.

## Files

- `src/Controller/Reader/BookmarksController.php` — dedup logic + API endpoints
- `assets/controllers/ui/bookmark_list_controller.js` — signed removal from the bookmarks page
- `assets/controllers/ui/card_bookmark_controller.js` — bookmark toggles on cards and article pages
- `templates/components/Molecules/ArticleSocialActions.html.twig` — article-page bookmark button
- `templates/pages/article.html.twig` — bookmark button placement
- `translations/messages.{en,de,es,fr,it,sl}.yaml` — bookmark translations

## Card shortcuts and pending publication

### Overview

Article cards now include a dedicated bookmark shortcut button.

The shortcut publishes kind `10003` bookmark updates without requiring a page reload between actions.

The article social strip uses the same IndexedDB-backed publish flow.

### Frontend flow

- Template: `templates/components/Molecules/Card.html.twig`
- Stimulus controller: `assets/controllers/ui/card_bookmark_controller.js`
- Article-page template: `templates/components/Molecules/ArticleSocialActions.html.twig`
- Bookmark button is rendered for logged-in users only.
- Coordinate format used for bookmarks: `kind:pubkey:slug`

### Reliability strategy

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

### APIs used

- `GET /api/bookmarks/current` (`api_bookmarks_current`)
- `POST /api/bookmarks/publish` (`api_bookmarks_publish`)

### Styling

Card bookmark button styling lives in:

- `assets/styles/03-components/card.css`

