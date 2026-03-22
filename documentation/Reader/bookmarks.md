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
| POST | `/api/bookmarks/publish` | `api_bookmarks_publish` | Receives a signed kind 10003 event, validates, persists, and broadcasts |

**Publish flow**:
1. Validate signed event structure and kind (must be 10003)
2. Verify event signature via `swentel\nostr\Event\Event::verify()`
3. Persist via `GenericEventProjector::projectEventFromNostrEvent()` — this handles:
   - Checking for existing newer versions
   - Persisting the new event
   - Deleting older replaceable versions (NIP-01 semantics)
4. Broadcast to user's relays via `NostrClient::publishEvent()`
5. Return relay success/failure summary

#### Frontend

**Stimulus Controller**: `nostr--nostr-bookmark` (`assets/controllers/nostr/nostr_bookmark_controller.js`)

| Property | Type | Description |
|----------|------|-------------|
| `coordinateValue` | String | Article coordinate (`30023:<pubkey>:<slug>`) |
| `fetchUrlValue` | String | URL for `GET /api/bookmarks/current` |
| `publishUrlValue` | String | URL for `POST /api/bookmarks/publish` |

| Target | Element | Purpose |
|--------|---------|---------|
| `button` | `<button>` | The bookmark toggle button |
| `icon` | `<svg>` | Bookmark icon (fill toggled) |
| `label` | `<span>` | Button text ("Bookmark"/"Bookmarked") |
| `status` | `<span>` | Status/error messages |

**Twig Component**: `BookmarkButton` (`src/Twig/Components/Molecules/BookmarkButton.php`)

Usage:
```twig
<twig:Molecules:BookmarkButton coordinate="30023:{{ article.pubkey }}:{{ article.slug }}" />
```

#### Styles
`assets/styles/03-components/bookmark-button.css` — bookmark button component styles.

## Files

- `src/Controller/Reader/BookmarksController.php` — dedup logic + API endpoints
- `src/Twig/Components/Molecules/BookmarkButton.php` — Twig component
- `templates/components/Molecules/BookmarkButton.html.twig` — component template
- `assets/controllers/nostr/nostr_bookmark_controller.js` — Stimulus controller
- `assets/styles/03-components/bookmark-button.css` — button styles
- `templates/pages/article.html.twig` — bookmark button placement
- `translations/messages.{en,de,es,fr,sl}.yaml` — bookmark translations

