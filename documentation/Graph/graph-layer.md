# Graph Layer

The graph layer is the host application's local index for supported Nostr event references. It is used when relational lookups need to answer questions such as:

- Which current articles or chapters are inside this publication index?
- Which magazines, reading lists, or collections reference this article coordinate?
- Which current event realizes a replaceable coordinate?
- Which exact event IDs are referenced, including targets not stored locally?

Raw Nostr events remain the source of truth. The graph tables are derived indexes that can be rebuilt from stored events and article rows.

## Current Shape

The current implementation is relational PostgreSQL, not Apache AGE. The earlier AGE proposal has been folded into this smaller, deployable design.

| Layer | Purpose |
|---|---|
| `event.d_tag` | Fast coordinate lookups for parameterized replaceable events. |
| `parsed_reference` | Normalized `a` coordinate, lowercase `e` exact-event, and lowercase `p` profile references; only `a` structural edges drive current traversal. |
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

`ReferenceParserService` parses supported `a`, lowercase `e`, and lowercase `p` tags. Address references retain their canonical coordinate and existing structural classification. Exact event IDs are stored separately from coordinates in `target_event_id`; repeated tags remain separate rows at their original source positions. For kind-1 events, the `e` tag's NIP-10 marker and relay hint are retained. The generic parser treats these `e` references as contextual, not structural. `GraphLookupService` does not yet resolve or traverse `target_event_id`; the reference remains available even when its target is absent locally. Uppercase `E` tags are not interpreted as lowercase `e`.

Valid lowercase `p` tags store a normalized 64-hex profile key in `target_pubkey`, with `target_ref_type` `profile`. Kind `3` references have relation `follows`, kind `39089` references have relation `member`, and other kinds have relation `references`. These rows are always non-structural and `is_resolvable` is false: profile lookup and traversal are not implemented. Uppercase `P` is not interpreted as `p`.

The optional slot-2 relay hint is retained only for a WebSocket scheme candidate of at most 2048 bytes, without validation or network use. `auxiliary_data` stores the complete JSON-encoded array of slots from index 2 onward when its encoded size is at most 4096 bytes; otherwise it is null. These untrusted slots can represent a petname, report classification, role, or other kind-specific data, and no display name is inferred. Raw event tags remain the source of truth, including over-limit or unencodable auxiliary data.

`CurrentVersionResolver` atomically selects the newer event for replaceable coordinates; equal timestamps use the lexicographically lower event ID. Exact event IDs do not pass through this latest-version selection.

`GraphLookupService::resolveChildren()` can repair missing current records from the article table when structural references exist but the main join finds no children. `fetchEventRows()` also falls back to article raw-event data. Backfilling current records covers both event and article tables.

## Repair and audit

The schema originated in migrations `Version20260315140000` and `Version20260315140001`; `Version20261114120000` adds exact event-ID and relay-hint columns plus the target-ID index, and `Version20261114130000` adds profile auxiliary data. Deploy migrations before the updated graph writers. Run the reference backfill again to index existing lowercase `p` tags; it includes `a`, `e`, and `p` tags.

~~~bash
docker compose exec php bin/console dn:graph:audit
docker compose exec php bin/console dn:graph:audit --limit=100
docker compose exec php bin/console dn:graph:rebuild-record "30040:<pubkey>:<slug>" --dry-run
~~~

The audit checks current versions, sampled reference counts, and orphan records. Reference-count sampling includes lowercase `p` tags, but equal counts do not prove that stored fields match the parser. Reference inserts are split into bounded statements to stay below PostgreSQL's bind-parameter limit. `--fix-versions`, `--fix-references`, or `--fix` enable repairs. The scheduled audit runs daily at 03:00 with `--fix` in [the crontab](../../docker/cron/crontab).

A record rebuild accepts `--cascade` to include structural children. The two backfill commands accept `--truncate` to clear and rebuild their derived tables; use this deliberately rather than as routine startup work.

Unfold accesses host graph data through its publication-tree adapter contracts. See [Unfold](../Unfold/unfold.md) for the package boundary. Older phase notes and the proposed Apache AGE rollout are superseded by this relational implementation.
