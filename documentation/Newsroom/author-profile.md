# Author Profiles & User Metadata

## Overview

User profiles are Nostr kind 0 events. The system maintains a `User` entity as a materialized projection of profile metadata, synced from Redis cache and relay fetches.

## Key Files

| Component | File |
|-----------|------|
| User entity | `src/Entity/User.php` |
| DTO | `src/Dto/UserMetadata.php` |
| Profile service | `src/Service/Nostr/UserProfileService.php` |
| Sync listener | `src/EventListener/UserMetadataSyncListener.php` |
| Sync service | `src/Service/UserMetadataSyncService.php` |
| Ingestion service | `src/Service/ProfileEventIngestionService.php` |
| Author controller | `src/Controller/AuthorController.php` |

## Profile Display

Author profile pages (`/p/{npub}`) show:
- Display name, NIP-05 badge, avatar, banner, about text
- Tabbed content: articles, highlights, media
- Articles fetched from Redis view store with DB fallback

## User Persistence

When a user logs in or their profile is fetched, a `User` entity is created/updated with:
- npub, hex pubkey, display name, NIP-05, picture, about, lud16
- Last-seen timestamp for activity tracking

## Lessons Learned

- **npub-to-hex conversion**: `UserMetadataSyncService` must convert npub to hex before calling `RedisCacheService::getMetadata()`. Missing this conversion caused silent sync failures.
- **UserMetadata DTO refactoring**: The `UserMetadata` DTO was introduced to provide typed access to profile fields instead of raw arrays/stdClass objects from Redis.
