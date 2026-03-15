# Graph Layer — Phases 0 & 1 (Relational Groundwork)

## Overview

This implements the relational prerequisites and groundwork for the AGE graph layer, as specified in `documentation/age-graph.md`. These phases are pure Symfony/Doctrine/PostgreSQL — no Apache AGE extension is required.

The primary motivation is eliminating relay round-trips in Unfold cache warming and magazine tree resolution. With these tables in place, `ContentProvider` can resolve a full magazine tree via a single recursive SQL query instead of 50+ WebSocket requests.

## What was added

### Phase 0: Relational Prerequisites

#### 1. `d_tag` column on `event` table

**Migration:** `Version20260315140000`

- Added `d_tag VARCHAR(512) DEFAULT NULL` to the `event` table
- Created partial composite index `idx_event_coord ON event (kind, pubkey, d_tag) WHERE d_tag IS NOT NULL`
- Backfills all existing parameterized replaceable events (kinds 30000–39999)
- Normalization rules:
  - `d` tag present with value → stored as-is
  - `d` tag present with empty string → stored as `''`
  - `d` tag absent on parameterized replaceable event → stored as `''`
  - `NULL` reserved for non-parameterized-replaceable events

#### 2. `eventId` deprecation on `Event` entity

- `getEventId()` now returns `$this->id` (backward compatible)
- `setEventId()` internally sets `$this->id` (backward compatible)
- All call sites updated to call `extractAndSetDTag()` on new events
- Column not dropped yet — will be removed in a future migration

#### 3. `RecordIdentityService`

**File:** `src/Service/Graph/RecordIdentityService.php`

Single authority for all identity derivation:
- `deriveCoordinate(kind, pubkey, dTag)` → canonical `"<kind>:<pubkey>:<d_tag>"`
- `deriveRecordUid(...)` → `"coord:<coordinate>"` or `"event:<id>"`
- `deriveRefType(kind)` → `'coordinate'` or `'event'`
- `deriveEntityType(kind)` → `'magazine'`, `'article'`, `'chapter'`, etc.
- `decomposeATag(aTagValue)` → `{kind, pubkey, d_tag}`
- `canonicalizeATag(aTagValue)` → normalized coordinate string

### Phase 1: Relational Groundwork

#### 4. `parsed_reference` table

**Migration:** `Version20260315140001`

Stores normalized outgoing references parsed from event tags. Phase 1 scope: `a` tags only.

| Column | Type | Description |
|--------|------|-------------|
| `id` | SERIAL PK | Auto-increment |
| `source_event_id` | VARCHAR(225) | Event that contains the reference |
| `tag_name` | VARCHAR(10) | Tag type (`a` in phase 1) |
| `target_ref_type` | VARCHAR(20) | `coordinate` or `event` |
| `target_kind` | INT | Target event kind |
| `target_pubkey` | VARCHAR(255) | Target author pubkey |
| `target_d_tag` | VARCHAR(512) | Target d-tag identifier |
| `target_coord` | VARCHAR(800) | Full canonical coordinate |
| `relation` | VARCHAR(50) | `contains`, `references` |
| `marker` | VARCHAR(50) | Optional tag marker |
| `position` | INT | Ordering position in source |
| `is_structural` | BOOLEAN | True for containment relationships |
| `is_resolvable` | BOOLEAN | True for trackable target kinds |

Indexes: `(source_event_id)`, `(target_coord)`, `(target_kind, target_pubkey, target_d_tag)`

#### 5. `ReferenceParserService`

**File:** `src/Service/Graph/ReferenceParserService.php`

Parses `a` tags from event tags arrays, classifies relations, and returns `ParsedReferenceDto` objects ready for bulk insert.

Structural source kinds (parent→child containment): 30040, 30004, 30005, 30006, 30003.

#### 6. `current_record` table

**Migration:** `Version20260315140001` (same migration)

Tracks the current (newest) event version for each replaceable coordinate.

| Column | Type | Description |
|--------|------|-------------|
| `record_uid` | VARCHAR(800) PK | `coord:<coordinate>` |
| `coord` | VARCHAR(800) UNIQUE | Canonical coordinate |
| `kind` | INT | Event kind |
| `pubkey` | VARCHAR(255) | Author pubkey |
| `d_tag` | VARCHAR(512) | D-tag identifier |
| `current_event_id` | VARCHAR(225) | Current winning event |
| `current_created_at` | BIGINT | Timestamp of current event |
| `updated_at` | TIMESTAMP | Last update time |

#### 7. `CurrentVersionResolver`

**File:** `src/Service/Graph/CurrentVersionResolver.php`

Atomic upsert with tie-break: `INSERT ... ON CONFLICT DO UPDATE WHERE` incoming is newer (or same timestamp + lower event id).

#### 8. `GraphLookupService`

**File:** `src/Service/Graph/GraphLookupService.php`

Read-side service using recursive CTEs:
- `resolveDescendants(rootCoord, maxDepth)` — full tree traversal
- `resolveChildren(parentCoord)` — immediate children
- `resolveAncestors(childCoord)` — reverse dependency lookup
- `resolveMagazineTree(magazineCoord)` — structured tree for Unfold
- `fetchEventRows(eventIds)` — bulk event data fetch

## Console Commands

### `dn:graph:backfill-references`

Backfills `parsed_reference` from all existing events that have `a` tags.

```bash
docker compose exec php bin/console dn:graph:backfill-references
docker compose exec php bin/console dn:graph:backfill-references --truncate  # clear and rebuild
```

### `dn:graph:backfill-current-records`

Backfills `current_record` from all existing replaceable events, processing in `created_at ASC` order so newest naturally wins.

```bash
docker compose exec php bin/console dn:graph:backfill-current-records
docker compose exec php bin/console dn:graph:backfill-current-records --truncate  # clear and rebuild
docker compose exec php bin/console dn:graph:backfill-current-records --kinds=30040,30023,30041  # specific kinds only
```

## Deployment Steps

1. Run migrations:
   ```bash
   docker compose exec php bin/console doctrine:migrations:migrate
   ```

2. Backfill references (one-time, can run during operation):
   ```bash
   docker compose exec php bin/console dn:graph:backfill-references
   ```

3. Backfill current records (one-time, can run during operation):
   ```bash
   docker compose exec php bin/console dn:graph:backfill-current-records
   ```

## Completed Integration Steps

### Unfold ContentProvider Refactored

`ContentProvider` now uses `GraphLookupService` as the primary path for all tree traversal:
- `getCategories()` → `resolveChildren(magazineCoord)` → bulk `fetchEventRows()`
- `getCategoryPosts()` → `resolveChildren(categoryCoord)` → bulk `fetchEventRows()`
- `getPost()` → `resolveDescendants(magazineCoord, 3)` → match by slug
- Falls back to relay round-trips via `NostrClient` only when graph data is empty

Result: cache warming resolves the entire magazine tree from local PostgreSQL in milliseconds instead of 50+ WebSocket requests.

### EventIngestionListener

**File:** `src/Service/Graph/EventIngestionListener.php`

Automatically updates `parsed_reference` and `current_record` when events are persisted. Hooked into:
- `GenericEventProjector` (primary event save path)
- `FetchAuthorContentHandler` (async article/media fetching)
- `SyncUserEventsHandler` (login-time event sync)

Safe to call multiple times for the same event (idempotent — deletes existing refs before reinserting).

### EventRepository::findByNaddr Optimized

Uses the new `d_tag` column index instead of JSONB scanning. Falls back to JSONB scan for events predating the backfill.

## Remaining Steps

1. **Add cron-based consistency check** to detect drift between relational and graph data
2. Proceed to AGE Phase 2 (Apache AGE graph extension) for richer graph queries if needed
3. Deprecate `MagazineProjector` once graph layer is proven stable

