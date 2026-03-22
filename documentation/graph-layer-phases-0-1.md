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

Backfills `current_record` from all existing replaceable events, processing in `created_at ASC` order so newest naturally wins. Includes a second pass over the `article` table for articles that don't have corresponding rows in the `event` table.

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

### Article Table Auto-Heal

**Problem:** Articles (kind 30023/30024) and publication content (kind 30041) may exist only in the `article` table, not in the `event` table. The original `dn:graph:backfill-current-records` only scanned `event`, so `current_record` entries were never created for these articles. This caused `resolveChildren` to return empty (structural references existed in `parsed_reference` but targets were missing from `current_record`), triggering the relay fallback path.

**Fix (two-pronged):**

1. **Auto-heal in `GraphLookupService::resolveChildren`** — when structural refs exist but the main JOIN returns empty, the service queries the `article` table by coordinate parts (`pubkey`, `slug`, `kind`), inserts missing `current_record` entries via `CurrentVersionResolver`, and retries the query. This is a lazy one-time operation per missing coordinate.

2. **Backfill command updated** — `dn:graph:backfill-current-records` now includes a second pass over the `article` table after the `event` table pass.

3. **`fetchEventRows` fallback** — when event IDs are not found in the `event` table, falls back to `article.raw` (full event JSON) and reshapes it to match the expected row format.

### EventRepository::findByNaddr Optimized

Uses the new `d_tag` column index instead of JSONB scanning. Falls back to JSONB scan for events predating the backfill.

## Remaining Steps

1. ~~**Add cron-based consistency check** to detect drift between relational and graph data~~ Done
2. Proceed to AGE Phase 2 (Apache AGE graph extension) for richer graph queries if needed (BLOCKED on human infrastructure approval)
3. ~~Deprecate `MagazineProjector` once graph layer is proven stable~~ Started — deprecation annotations added, `GraphMagazineListService` wired as primary path
4. Remove `MagazineProjector`, `ProjectMagazineMessage`, `ProjectMagazineMessageHandler`, `ProjectMagazinesCommand`, and `project_magazines.sh` cron once graph layer has been stable for one release cycle
5. Drop `Magazine` entity and table

---

## Phase 1.5: Operational Hardening

### `dn:graph:audit` command

**File:** `src/Command/GraphAuditCommand.php`

Cron-safe consistency checker with three audits:

1. **Current-record freshness** — compares `current_record.current_event_id` against the actual newest event per coordinate in the `event` table using a `LATERAL` subquery. Reports stale entries.
2. **Parsed-reference completeness** — samples events with `a` tags, re-parses via `ReferenceParserService`, and compares expected vs stored reference counts.
3. **Orphan detection** — finds `current_record` entries whose `current_event_id` no longer exists in either `event` or `article` tables.

Flags: `--fix`, `--fix-versions`, `--fix-references`, `--limit N`.

Cron: runs daily at 3 AM with `--fix` in `docker/cron/crontab`.

### `dn:graph:rebuild-record` command

**File:** `src/Command/GraphRebuildRecordCommand.php`

Single-record rebuild for surgical repair:

```bash
docker compose exec php bin/console dn:graph:rebuild-record "30040:<pubkey>:<slug>"
docker compose exec php bin/console dn:graph:rebuild-record "30040:<pubkey>:<slug>" --cascade
docker compose exec php bin/console dn:graph:rebuild-record "30040:<pubkey>:<slug>" --dry-run
```

- Finds all events matching the coordinate in both `event` and `article` tables
- Clears and re-runs `CurrentVersionResolver::updateIfCurrent()` in `created_at ASC` order
- Re-parses references for the winning event
- `--cascade` rebuilds all structural children recursively

### `GraphMagazineListService`

**File:** `src/Service/Graph/GraphMagazineListService.php`

Graph-backed replacement for `MagazineRepository` listing queries:
- `listAllMagazines()` — all top-level magazines from `current_record` + event metadata
- `listByPubkey(pubkey)` — filtered by author
- `countMagazines()` — count of kind 30040 records

Wired into `ZineList` (primary path) and `AdminDashboardService` (magazine counts).

### Magazine deprecation annotations

The following classes are marked `@deprecated`:
- `MagazineProjector`, `ProjectMagazineMessage`, `ProjectMagazineMessageHandler`, `ProjectMagazinesCommand`

### Unit tests

Tests in `tests/Unit/Service/Graph/`:
- `RecordIdentityServiceTest` — coordinate derivation, record UID, ref type, entity type, a-tag decomposition
- `ReferenceParserServiceTest` — a-tag parsing, ordering, structural classification, marker extraction
- `EventIngestionListenerTest` — processEvent, processRawEvent, d-tag extraction
