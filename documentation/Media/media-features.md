# Media Features

## Overview

The media features enhance multimedia content discovery and presentation in the newsroom. Three interconnected capabilities were implemented:

1. **Tabbed Media Discovery** — The `/multimedia` page now uses a tabbed interface (Latest, Follows, Interests)
2. **Media Follows (kind 10020)** — NIP-68 multimedia follow list support
3. **Curation Collection Views** — Restyled kind 30005 (videos) and kind 30006 (pictures)

## Tabbed Media Discovery

### Architecture

The media discovery page (`/multimedia`) mirrors the home feed tabbed pattern:

- **Route**: `GET /multimedia` renders the tab shell with Turbo Frame lazy loading
- **Route**: `GET /multimedia/tab/{tab}` returns `<turbo-frame id="media-tab-content">` partials
- **Controller**: `MediaDiscoveryController` dispatches to per-tab methods
- **Stimulus**: `media--media-discovery-tabs` handles client-side tab switching with abort/timeout/rollback

### Tabs

| Tab | Auth Required | Data Source | Fallback |
|-----|--------------|-------------|----------|
| Latest | No | Cache → DB (`findNonNSFWMediaEvents`) | Empty state |
| Follows | Yes | kind 10020 → kind 3 → `findNonNSFWMediaEventsByPubkeys` | Regular follows |
| Interests | Yes | kind 10015 → `findMediaEventsByHashtags` | Empty state |

### Files

- `src/Controller/Media/MediaDiscoveryController.php` — tabbed controller
- `templates/pages/media-discovery.html.twig` — tab shell
- `templates/media/tabs/_latest.html.twig` — latest tab partial
- `templates/media/tabs/_follows.html.twig` — follows tab partial
- `templates/media/tabs/_interests.html.twig` — interests tab partial
- `assets/controllers/media/media-discovery-tabs_controller.js` — Stimulus controller

## Media Follows (kind 10020)

### NIP-68 Compliance

Kind 10020 is a replaceable event (one per user, latest wins) containing `"p"` tags for followed media creators. Structure:

```json
{
  "kind": 10020,
  "tags": [
    ["p", "<hex-pubkey>", "<optional-relay-hint>", "<optional-petname>"],
    ["p", "<hex-pubkey>"]
  ]
}
```

### Implementation

- **Enum**: `KindsEnum::MEDIA_FOLLOWS = 10020`
- **Sync**: Added to `SyncUserEventsHandler::SYNC_KINDS` so it's fetched on login
- **Service**: `UserProfileService::getMediaFollows()` — DB-first with relay fallback, same pattern as `getFollows()`
- **Facade**: `NostrClient::getUserMediaFollows()` — pass-through
- **Fallback**: If no kind 10020 event exists, the Follows tab falls back to the regular kind 3 follow list with a banner explaining the fallback

### Files

- `src/Enum/KindsEnum.php` — added `MEDIA_FOLLOWS` case
- `src/MessageHandler/SyncUserEventsHandler.php` — syncs kind 10020 on login
- `src/Service/Nostr/UserProfileService.php` — `getMediaFollows()` method
- `src/Service/Nostr/NostrClient.php` — `getUserMediaFollows()` facade
- `src/Repository/EventRepository.php` — `findMediaEventsByPubkeys()`, `findNonNSFWMediaEventsByPubkeys()`

## Curation Collection Views

### Async sync for missing referenced media

When a kind `30005`/`30006` curation event references media via `e` tags that are not yet present in the local database, the page now renders immediately with placeholders and starts a background sync:

1. `AuthorController::curationSet()` detects missing referenced event IDs.
2. It dispatches `FetchMissingCurationMediaMessage` through Symfony Messenger.
3. `FetchMissingCurationMediaHandler` batch-fetches the missing IDs from relays via `NostrClient::getEventsByIds()`.
4. New events are persisted locally and published to Mercure on `/curation/{curationId}/media-sync`.
5. The page subscribes with `content--curation-mercure` and refreshes itself when the sync completes.

This keeps the initial response fast while still populating boards as the missing media arrives.

### Picture Collections (kind 30006) — Instagram-style

The picture curation set view displays images in a uniform square grid (3 columns, 4 on wide screens, 2 on mobile). Clicking an image opens a full-screen lightbox with keyboard navigation (←/→/Esc).

**Files:**
- `templates/pages/curation-pictures.html.twig` — grid template with lightbox
- `assets/styles/04-pages/curation-picture-grid.css` — grid + lightbox styles
- `assets/controllers/media/picture-gallery_controller.js` — lightbox Stimulus controller

### Video Collections (kind 30005) — YouTube Playlist-style

The video curation set view uses a two-column layout: a main video player on the left with a scrollable playlist sidebar on the right. Clicking a playlist item loads that video in the player.

**Files:**
- `templates/pages/curation-videos.html.twig` — playlist template
- `assets/styles/04-pages/curation-video-playlist.css` — playlist layout styles
- `assets/controllers/media/video-playlist_controller.js` — playlist Stimulus controller

## Translation Keys

All new user-facing text is under the `media:` namespace in `translations/messages.en.yaml`:

- `media.tab.latest`, `media.tab.follows`, `media.tab.interests`
- `media.follows.*` — sign-in required, no follows, fallback banner
- `media.interests.*` — sign-in required, no interests, configure link
- `media.collections.*` — heading, videos, pictures

