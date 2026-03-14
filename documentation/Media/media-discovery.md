# Media Discovery

## Overview

Database-backed media discovery system for NIP-68 (images, kind 20) and NIP-71 (video, kinds 21/22) events. Events are fetched from relays, persisted to the database, and displayed in a masonry layout.

## Architecture

1. **`CacheMediaDiscoveryCommand`** — dispatches async fetch and rebuilds cache from database (cron: every 6 hours)
2. **`FetchMediaEventsHandler`** — fetches from Nostr relays and persists to `Event` entity
3. **`EventRepository`** — queries media events with filtering (NSFW, muted users, hashtags)
4. **`MediaDiscoveryController`** — displays cached events with database fallback
5. **`Event` entity** — helper methods for media URL extraction, NSFW detection

## Media Manager

Route: `/media-manager` — allows users to browse and manage their uploaded media. Integrates with Blossom (NIP-96) and NIP-96 media providers.

### Key Files

| Component | File |
|-----------|------|
| Discovery controller | `src/Controller/Media/MediaDiscoveryController.php` |
| Manager API | `src/Controller/Api/MediaManagerApiController.php` |
| Media publisher | `src/Service/Media/MediaPublisher.php` |
| Provider registry | `src/Service/Media/MediaProviderRegistry.php` |
| Blossom provider | `src/Service/Media/BlossomMediaProvider.php` |
| NIP-96 provider | `src/Service/Media/Nip96MediaProvider.php` |
| Relay query | `src/Service/Media/MediaRelayQueryService.php` |

## Hydration Worker

Media events are ingested via the `subscribe-local-media` worker (kinds 20, 21, 22). The strfry router is configured to accept these event kinds.

## Performance

- Cache holds a precomputed subset for fast page loads
- NSFW filtering via `Event::isNsfw()` (checks content-warning tags)
- Muted pubkeys filtered via `MutedPubkeysService`
- Database index on `kind` column for efficient queries

