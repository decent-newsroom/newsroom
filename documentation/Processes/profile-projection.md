# Profile Projection System

## Overview

Async profile aggregation for Nostr users: **local relay ingestion → raw event persistence → async projection update**. Fast, non-blocking login while maintaining accurate profile data.

## Architecture

| Component | File | Purpose |
|-----------|------|---------|
| Raw event store | `src/Entity/Event.php` | Stores all ingested Nostr events (kind 0, 10002) |
| Profile projection | `src/Entity/User.php` | Materialized view of user profile |
| Async handler | `src/MessageHandler/UpdateProfileProjectionHandler.php` | Processes projection updates |
| Batch handler | `src/MessageHandler/BatchUpdateProfileProjectionHandler.php` | Batch profile updates |
| Refresh worker | `src/Command/ProfileRefreshWorkerCommand.php` | Periodic refresh for all users |
| Ingestion service | `src/Service/ProfileEventIngestionService.php` | Parses kind 0 events into User entities |

## Profile Facets

| Facet | Kind | Data |
|-------|------|------|
| Metadata | 0 | display_name, name, nip05, about, picture, banner, lud16 |
| Relay list | 10002 | Read/write relay hints |

## Flows

**Login**: User authenticates → `UpdateProfileProjectionMessage` dispatched async → profile populated from Redis cache/DB/network. User sees the app immediately; profile fills in asynchronously.

**Background refresh**: `ProfileRefreshWorkerCommand` runs in the consolidated worker, batching profile updates to avoid relay overload. Coalesces updates during ingestion bursts.

**Metadata sync**: `UserMetadataSyncListener` triggers on profile changes, ensuring the User entity stays current with the latest kind 0 event.

## Lessons Learned

- **npub-to-hex conversion**: `UserMetadataSyncService` must convert npub identifiers to hex pubkeys before querying — a missed conversion caused silent sync failures.
- **Database fallback**: When Redis cache misses for a profile, fall back to the User entity's stored metadata rather than making a relay call on every request.

