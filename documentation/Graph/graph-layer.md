# Graph Layer

The graph layer is the host application's local index for Nostr structures that reference other records by coordinate. It is used when relational lookups need to answer questions such as:

- Which current articles or chapters are inside this publication index?
- Which magazines, reading lists, or collections reference this article coordinate?
- Which current event realizes a replaceable coordinate?

Raw Nostr events remain the source of truth. The graph tables are derived indexes that can be rebuilt from stored events and article rows.

## Current Shape

The current implementation is relational PostgreSQL, not Apache AGE. The earlier AGE proposal has been folded into this smaller, deployable design.

| Layer | Purpose |
|---|---|
| `event.d_tag` | Fast coordinate lookups for parameterized replaceable events. |
| `parsed_reference` | Normalized outgoing references parsed from event tags, currently focused on structural `a` tags. |
| `current_record` | Current winning event for each replaceable coordinate. |
| `GraphLookupService` | Recursive CTE queries for children, descendants, ancestors, magazine trees, and bulk event row fetches. |
| `EventIngestionListener` | Keeps graph tables updated when events are projected or persisted. |

## Important Classes

| File | Responsibility |
|---|---|
| `src/Service/Graph/RecordIdentityService.php` | Builds canonical coordinates, record ids, and entity-type classifications. |
| `src/Service/Graph/ReferenceParserService.php` | Parses supported Nostr references into `ParsedReferenceDto` objects. |
| `src/Service/Graph/CurrentVersionResolver.php` | Applies latest-version rules for replaceable coordinates. |
| `src/Service/Graph/GraphLookupService.php` | Read API for tree traversal and reverse lookup. |
| `src/Service/Graph/GraphMagazineListService.php` | Graph-backed magazine listing support. |
| `src/Service/Graph/EventIngestionListener.php` | Idempotent graph update hook used by ingestion/publish paths. |

## Commands

```bash
docker compose exec php bin/console dn:graph:backfill-references
docker compose exec php bin/console dn:graph:backfill-current-records
docker compose exec php bin/console dn:graph:rebuild-record <coordinate>
docker compose exec php bin/console dn:graph:audit
```

Use `--dry-run` where available before destructive or expensive repair runs.

## Deployment Notes

1. Run the migrations that add `d_tag`, `parsed_reference`, and `current_record`.
2. Backfill references and current records once.
3. Let `EventIngestionListener` maintain the tables for new data.
4. Use `dn:graph:audit` after large imports, deletions, or projection changes.

## What It Replaces

The graph layer reduces reliance on relay round-trips for magazine and publication tree resolution. Unfold and magazine listing code should prefer graph lookups and fall back to relay/network reads only when local graph data is incomplete.

## Non-Goals

- It does not replace the raw `event` table.
- It does not index every possible Nostr tag relationship.
- It does not remove the need for relay sync.
- It does not currently require Apache AGE.

## Coordinate and version rules

`RecordIdentityService` derives `kind:pubkey:d_tag` coordinates and `coord:<coordinate>` record identifiers. Addressable events with a missing or empty `d` tag use an empty string; non-addressable events keep a null `d_tag`.

`ReferenceParserService` classifies structural `a` references for publication and curation indexes. `CurrentVersionResolver` atomically selects the newer event; equal timestamps use the lexicographically lower event ID.

`GraphLookupService::resolveChildren()` can repair missing current records from the article table when structural references exist but the main join finds no children. `fetchEventRows()` also falls back to article raw-event data. Backfilling current records covers both event and article tables.

## Repair and audit

The schema originated in migrations `Version20260315140000` and `Version20260315140001`. Use normal Doctrine migrations, then backfill references and current records for existing data.

~~~bash
docker compose exec php bin/console dn:graph:audit
docker compose exec php bin/console dn:graph:audit --limit=100
docker compose exec php bin/console dn:graph:rebuild-record "30040:<pubkey>:<slug>" --dry-run
~~~

The audit checks current versions, reference completeness, and orphan records. `--fix-versions`, `--fix-references`, or `--fix` enable repairs. The scheduled audit runs daily at 03:00 with `--fix` in [the crontab](../../docker/cron/crontab).

A record rebuild accepts `--cascade` to include structural children. The two backfill commands accept `--truncate` to clear and rebuild their derived tables; use this deliberately rather than as routine startup work.

Unfold accesses host graph data through its publication-tree adapter contracts. See [Unfold](../Unfold/unfold.md) for the package boundary. Older phase notes and the proposed Apache AGE rollout are superseded by this relational implementation.
