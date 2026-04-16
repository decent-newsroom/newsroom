# Replaceable Event Cleanup

## Overview

All event ingestion paths automatically clean up older versions of replaceable and parameterized-replaceable events (NIP-01) when a newer version is persisted. This "while-you're-at-it" approach keeps the database lean without requiring separate maintenance jobs.

## What Gets Cleaned

| Event Type | Rule | Examples |
|---|---|---|
| Replaceable (kind 0, 3, 10000–19999) | One event per pubkey + kind | Kind 0 (metadata), kind 3 (follows), kind 10002 (relay list), kind 10003 (bookmarks), kind 10015 (interests) |
| Parameterized replaceable (kind 30000–39999) | One event per pubkey + kind + d-tag | Kind 30023 (articles), kind 30040 (magazine index), kind 34235/34236 (media sets) |
| Article revisions | One Article row per pubkey + slug | Older revisions of the same article coordinate are removed from the `article` table |

## Ingestion Paths Covered

- **`GenericEventProjector`** — relay subscription workers, generic event ingestion
- **`PersistGatewayEventsHandler`** — relay gateway batch persistence (async Messenger)
- **`ArticleEventProjector`** — article-specific ingestion from relay subscriptions
- **`MediaEventProjector`** — media event ingestion (kinds 20, 21, 22, 34235, 34236)

## Shared Service

All cleanup logic is centralized in `ReplaceableEventCleanupService` (`src/Service/ReplaceableEventCleanupService.php`):

- `removeOlderEventVersions(Event, EntityManagerInterface)` — deletes older Event rows for the same coordinate
- `removeOlderArticleRevisions(Article, EntityManagerInterface)` — deletes older Article rows for the same pubkey + slug

NIP-01 tie-breaking is respected: when two events share the same `created_at` timestamp, the one with the lexicographically lower event ID wins.

## Failure Handling

Cleanup failures are logged as warnings but never block event ingestion. Stale rows are harmless since all queries sort by `created_at DESC` and take the first result.

