# PostgreSQL Graph Extension Spec for Linked-Record Lookup

## Purpose

This document specifies how to extend the Decent Newsroom PostgreSQL database with a graph layer so that lookup of linked records becomes simple, fast, and reliable.

The core problem is not storing Nostr events. Postgres already does that well.

The problem is **resolving linked structures whose members are often referenced by coordinate rather than static event id**, especially when those targets are replaceable or parameterized replaceable events.

Typical examples in DN:

* magazines containing categories and article pointers
* categories containing article pointers
* publication content events (kind 30041) referenced by magazine indices
* drive-like structures containing nested directories and items
* future collection types built from Nostr references rather than owned rows

The graph layer is meant to solve traversal and dependency lookup.

It is **not** the source of truth for raw event storage.

---

## Design goals

1. Keep raw Nostr events in normal relational tables.
2. Add a derived graph projection for traversal-heavy lookups.
3. Model both:

    * stable identities (coordinates or immutable ids)
    * concrete event versions
4. Make it cheap to answer questions like:

    * "Which current article events are in this magazine right now?"
    * "What changed downstream when this category was updated?"
    * "Which publications depend on this article coordinate?"
    * "What is the resolved current tree for this collection?"
5. Support DN-specific content structures without hardcoding only one content type.
6. Fit the Symfony + Postgres stack cleanly.
7. **Deprecate the `Magazine` entity and replace `MagazineProjector`** — the graph should make the dedicated Magazine projection unnecessary by providing generic, efficient tree traversal for any 30040-rooted structure.

---

## Non-goals

This graph layer is not intended to:

* replace normal SQL for simple filtering and listing
* become the canonical raw-event database
* store every possible Nostr tag relationship indiscriminately
* require every query in the app to use graph traversal
* solve relay sync by itself

---

## Core DN assumption

In DN, content is Nostr-native.

That means:

* records are ultimately Nostr events
* many references are pointers, not foreign keys
* many important pointers resolve through coordinates, not fixed ids
* parameterized replaceable events make "latest current state" part of the lookup problem

A plain relational schema can store all of this, but traversal and reverse-dependency lookup become awkward once structures are nested.

That is where the graph projection helps.

---

## Summary of the approach

Use a **hybrid relational + graph model**:

* **Relational layer** stores raw events, parsed tags, cache fields, and current-version state.
* **Graph layer** stores linked-record topology and version relationships.

The graph should be treated as a **derived index** rebuilt from canonical event data.

That keeps operational risk lower.

---

# 0. Prerequisites — relational cleanup before graph work

Before introducing the graph layer, the relational base needs two prerequisite fixes.

## 0.1 Add `d_tag` column to the `event` table

The `d` tag is the identity axis for every parameterized replaceable event (kinds 30000–39999). Currently DN extracts it on-the-fly by scanning the JSONB `tags` array each time. This is both slow and error-prone.

**Action:** add a nullable `VARCHAR(512)` column `d_tag` to the `event` table.

Populate it on insert from the first `["d", "..."]` tag. For events outside the 30000–39999 range, leave it `NULL`.

**Indexing:** create a composite index on `(kind, pubkey, d_tag)`. This is the canonical coordinate lookup path and will be used constantly by the graph projection, the `CurrentVersionResolver`, and any query that resolves a coordinate to its current event.

```sql
ALTER TABLE event ADD COLUMN d_tag VARCHAR(512) DEFAULT NULL;
CREATE INDEX idx_event_coord ON event (kind, pubkey, d_tag)
  WHERE d_tag IS NOT NULL;
```

**Backfill:** a one-time migration should populate `d_tag` for all existing parameterized replaceable events by extracting the value from the `tags` JSONB.

### Empty `d` tags vs absent `d` tags

The Nostr protocol allows a parameterized replaceable event to have a `d` tag with an empty string value `["d", ""]`. It also allows the `d` tag to be absent entirely. **These two cases are semantically equivalent in Nostr** — both resolve to the coordinate `<kind>:<pubkey>:` (with a trailing empty identifier).

DN must normalize both cases to the same canonical form. The recommended rule:

* If the `d` tag is present with an empty string value → store `d_tag = ''` (empty string, not `NULL`).
* If the `d` tag is absent entirely on a parameterized replaceable event → also store `d_tag = ''`.
* `d_tag = NULL` is reserved for events that are not parameterized replaceable (kinds outside 30000–39999).

This means `NULL` signals "not applicable" and empty string signals "applicable but no identifier". The coordinate composite index `(kind, pubkey, d_tag)` will correctly unify both absent and empty `d` tags.

## 0.2 Resolve `id` / `eventId` duplication on the `Event` entity

The `Event` entity currently has two columns that store the same Nostr event id:

* `id` (VARCHAR 225) — the Doctrine primary key, set to the Nostr event id hex string.
* `event_id` (VARCHAR 225, nullable) — a separate column that is always set to the same value as `id` wherever events are created (see `GenericEventProjector`, `UserProfileService`, `SyncUserEventsHandler`, `FetchAuthorContentHandler`, etc.).

This duplication is confusing and wastes storage. **Only `id` should remain.** The `event_id` column should be dropped.

**Action:**

1. Audit all code that calls `setEventId()` or `getEventId()` on the `Event` entity. Every call site currently sets `eventId` to the same value as `id`.
2. Deprecate `getEventId()` / `setEventId()` on `Event`. Internally alias `getEventId()` to return `$this->id` during the transition.
3. Remove the `event_id` column in a migration once all call sites use `getId()`.
4. Note: other entities (`Article`, `Highlight`, `MediaPostCache`, `MediaAssetPostLink`) legitimately use an `eventId` column as a foreign reference — these are not duplicates and should remain.

This cleanup is prerequisite because the graph layer refers to event ids extensively, and having a single canonical column avoids ambiguity.

---

# 1. Conceptual model

## 1.1 Two identity levels

The implementation must distinguish between:

### A. Stable record identity

A stable thing that can be linked to over time.

Examples:

* parameterized replaceable coordinate: `30040:<pubkey>:newsroom-magazine`
* parameterized replaceable coordinate: `30023:<pubkey>:my-article`
* parameterized replaceable coordinate: `30041:<pubkey>:chapter-1`
* replaceable coordinate without `d` tag if applicable
* immutable event id, when the link truly targets one concrete event

This level answers: **what thing is this reference pointing at?**

### B. Concrete event version

A specific published Nostr event id.

This level answers: **which actual event currently realizes that thing?**

This distinction is essential.
Without it, replaceable-event traversal becomes brittle.

---

## 1.2 Why both are needed

Suppose a magazine points to an article coordinate, not a fixed event id.

The article is later updated.

If the graph only stores event ids, the old link remains stale.
If the graph only stores coordinates, you lose version history and exact realization.

So DN needs both:

* stable node for traversal of intended structure
* version node for exact current resolution and history

---

# 2. Recommended graph model

## 2.1 Node labels

Use at least these node types.

### `Record`

A stable referenceable identity.

Properties:

* `uid` — internal unique graph-safe identifier
* `ref_type` — `coordinate` or `event`
* `kind` — integer Nostr kind when known
* `pubkey` — hex pubkey when applicable
* `d_tag` — string or null
* `coord` — canonical coordinate string when applicable
* `event_id` — only for immutable-event records
* `is_replaceable` — boolean
* `is_param_replaceable` — boolean
* `entity_type` — optional DN semantic hint such as `magazine`, `category`, `article`, `chapter`, `directory`, `media`, `unknown`
* `deleted` — boolean default false

### `EventVersion`

A concrete ingested Nostr event.

Properties:

* `event_id` — Nostr event id, unique
* `kind`
* `pubkey`
* `created_at`
* `db_pk` — relational row id
* `ingested_at`

### Optional later nodes

Do not require these in phase 1, but leave room for them:

* `Author`
* `Relay`
* `TagValue`
* `MediaBlob`

Phase 1 should stay lean.

---

## 2.2 Edge types

### `(:EventVersion)-[:VERSION_OF]->(:Record)`

This links one concrete event to the stable record it realizes.

Examples:

* article event version -> article coordinate record
* category event version -> category coordinate record
* chapter event version -> chapter coordinate record
* immutable event version -> immutable event record

### `(:Record)-[:CURRENT]->(:EventVersion)`

This points from a stable record to its current concrete event version.

There should be at most one active current edge per record, newest known.

**Current-version rule:** the event with the highest `created_at` wins. When two events have the same `created_at`, the one with the lexicographically lower event `id` wins (consistent with NIP-01 replaceable semantics). There is no need to store or resolve old revisions during normal operation — only the newest matters.

### `(:Record)-[:REFERS_TO]->(:Record)`

Stable topology edge.

This is the most important traversal edge for DN.
It represents the current outgoing references of the current version of the source record.

Properties should include:

* `relation` — e.g. `contains`, `features`, `references`, `replies_to`, `depends_on`
* `tag_name` — original tag key if relevant
* `marker` — optional marker semantics
* `position` — integer if source ordering matters
* `path_hint` — optional string for debugging
* `source_current_event_id` — event id from which this current edge was derived
* `updated_at`

### `(:EventVersion)-[:REFERS_TO_VERSIONED]->(:Record)`

Optional but recommended.

This stores raw outgoing references parsed from a specific event version. It is useful for debugging, diffs, and history, but runtime tree resolution should primarily use `Record -> REFERS_TO -> Record` on current topology.

### Optional reverse semantics

Do not create separate reverse edges initially. Use graph traversal or SQL joins instead unless performance clearly requires explicit reverse edges.

---

# 3. Canonical identity rules

## 3.1 Coordinate records

When an event is replaceable or parameterized replaceable, derive a canonical coordinate.

Recommended canonical string:

* parameterized replaceable: `<kind>:<pubkey>:<d_tag>`
* replaceable without `d`: `<kind>:<pubkey>`

This canonical string must be normalized consistently everywhere.

### Normalization rules

* pubkeys stored lowercase hex
* kind stored as integer
* `d_tag` stored exactly as parsed after any DN-defined normalization
* empty `d` tag value (`["d", ""]`) and absent `d` tag on a parameterized replaceable event are treated as equivalent — both normalize to an empty string `d_tag`, producing a coordinate like `30040:<pubkey>:`. See section 0.1 for details.
* `d_tag = NULL` (SQL) is reserved for non-parameterized-replaceable events only.

### `RecordIdentityService` as single authority

All canonical identity derivation — coordinate strings, `record_uid` generation, `ref_type` classification, and `entity_type` mapping — **must** go through one service: `RecordIdentityService`. No other service, controller, or command should independently compute coordinates or record UIDs.

This is the single most important rule for avoiding identity drift and subtle bugs. If two code paths produce slightly different canonical strings for the same event, the graph becomes corrupted silently.

---

## 3.2 Immutable event records

When a link targets a specific event id, create a `Record` with:

* `ref_type = event`
* `event_id = <target id>`
* `uid = event:<id>`

This allows a uniform traversal target even when references mix coordinates and fixed ids.

---

## 3.3 Semantic entity type

`entity_type` is an optimization hint, not the primary identity.

Derive it from kind and/or DN parsing rules.

Examples:

* `30040` may map to `magazine`, `category`, or `reading_list` depending on role/content and structural position
* `30023` maps to `article`
* `30041` maps to `chapter`
* kind `20` or related media kinds may map to `media`

If uncertain, set `entity_type = unknown` rather than guessing. Use `type` tag if it exists.

---

# 4. Relational layer expectations

The graph projection depends on a clean relational base.

The implementation should assume at least the following relational concerns exist or be introduced.

## 4.1 Raw events table

A canonical table storing ingested Nostr events.

After prerequisite 0.1, the `event` table has columns: `id` (PK, Nostr event id hex), `kind`, `pubkey`, `content`, `created_at`, `tags` (JSONB), `sig`, and the new `d_tag`.

After prerequisite 0.2, the redundant `event_id` column has been removed.


## 4.2 Parsed reference table

A normalized table for parsed outgoing references from each event.

Suggested fields:

* `id`
* `source_event_id`
* `tag_name` — the tag key (e.g. `a`, `e`, `p`)
* `target_ref_type` — `coordinate` or `event`
* `target_kind`
* `target_pubkey`
* `target_d_tag`
* `target_event_id`
* `target_coord` — precomputed canonical coordinate string (for `a` tag references)
* `relation`
* `marker`
* `position`
* `is_structural` boolean
* `is_resolvable` boolean

This table is useful even outside the graph extension.

**Backfill plan:** this table should be backfilled from existing event data. For the initial backfill, **only `a` tags need to be parsed** — these are the structural coordinate references that the graph layer depends on. Other tag types (`e`, `p`, `t`, etc.) can be added later when needed. The backfill should process all events that have `a` tags, extracting each `a` tag into a parsed-reference row with the decomposed coordinate fields.

**Indexing:** ensure indexes on:

* `(source_event_id)` — for "what does this event reference?"
* `(target_coord)` — for "what references this coordinate?"
* `(target_kind, target_pubkey, target_d_tag)` — for flexible target lookup

## 4.3 Current-record table

A table that resolves stable coordinate identity to the current active event version.

Suggested fields:

* `record_uid`
* `coord`
* `kind`
* `pubkey`
* `d_tag`
* `current_event_id`
* `current_created_at`
* `updated_at`

This table can act as the bridge between relational logic and graph maintenance.

**Current-version rule:** the event with the highest `created_at` for a given `(kind, pubkey, d_tag)` tuple is current. When populating this table, insert or update only if `created_at` of the incoming event is greater than (or equal to, with tie-break on event id) the existing `current_created_at`. Old revisions can be safely skipped — they are never current and do not need entries in this table.

---

# 5. Graph behavior

## 5.1 The graph is a projection, not a source of truth

All graph nodes and edges must be derivable from relational data.

That means the system should be able to:

* rebuild the graph fully from relational tables
* rebuild one record's local topology incrementally
* verify graph consistency against relational truth

This is operationally important.

---

## 5.2 Current-topology rule

For runtime traversal, `Record -> REFERS_TO -> Record` edges should represent the outgoing references of the **current** version of the source record.

That means when a new current event supersedes the old one:

1. current pointer changes
2. outgoing stable edges for that record are replaced
3. downstream dependents can be invalidated or re-resolved

This keeps traversal simple.

---

## 5.3 History rule

Historical event-level references may still be stored through `EventVersion -> REFERS_TO_VERSIONED -> Record`, but the app should not use them for "current tree" rendering.

Those are for:

* audit
* debugging
* diffing
* future timeline features

---

# 6. DN-specific use cases this must support

## 6.1 Magazine tree resolution

Given a magazine coordinate, resolve the current active set of descendants.

For DN this means:

* start at magazine `Record`
* follow `REFERS_TO` edges to category, chapter, and article records
* for every descendant record, resolve `CURRENT` to concrete event versions
* return ordered current event ids for rendering/indexing

This is the central use case.

### Replacing `MagazineProjector` and deprecating `Magazine` entity

The current codebase projects kind 30040 events into a dedicated `Magazine` entity via `MagazineProjector`. This entity stores denormalized JSON blobs for categories, contributors, contained kinds, and relay pools. It is run on a 10-minute cron and also triggered synchronously from the magazine wizard.

**The graph layer should fully replace this projection.** Once AGE can traverse 30040 trees, the `Magazine` entity becomes redundant:

* Tree structure is answered by graph traversal, not a JSON column.
* Category metadata is answered by resolving each child `Record` to its current `EventVersion`.
* Contributor lists are answered by collecting pubkeys from descendant article events.
* "Which magazines exist?" is answered by querying `Record` nodes where `kind = 30040` and `entity_type = 'magazine'`.

**Migration path:**

1. Build graph layer and verify it produces correct tree results.
2. Rewrite consumers that currently query `Magazine` to use `GraphLookupService`.
3. Primary consumer: **Unfold** (see section 6.5).
4. Deprecate `MagazineProjector`, `ProjectMagazineMessage`, `ProjectMagazineMessageHandler`, and the `app:project-magazines` cron entry.
5. Deprecate the `Magazine` entity. Keep the table temporarily for rollback safety, then drop it.
6. Remove the `MagazineRepository` and related admin/wizard code that writes to `Magazine`.

---

## 6.2 Reverse dependency lookup

Given an article coordinate or event, find which categories, magazines, or collections currently include it.

This supports:

* invalidation
* reindexing
* cache refresh
* dependency-aware publishing
* admin/debug tooling

---

## 6.3 Replaceable update propagation

When a parameterized replaceable event is updated:

* resolve whether it becomes current
* if yes, refresh the source record's outgoing topology
* mark ancestor records dirty using reverse traversal
* optionally trigger downstream manifest/index regeneration

---

## 6.4 Orphan detection

Find records or current versions that are not reachable from any root collections of interest.

This supports:

* cleanup
* diagnostics
* identifying unused or broken structures

---

## 6.5 Manifest generation / Unfold support

**Unfold is the primary consumer of the graph layer.**

The `UnfoldBundle` renders magazine subdomains by traversing 30040 event trees, resolving categories and articles, and building HTML pages. Currently it does this by making relay requests through `NostrClient` with an SWR cache layer (`ContentProvider`).

The graph should make it easy to answer:

* "What is the current resolved structure of this publication?"
* "Which event ids should be included in the generated manifest?"
* "What changed since last build?"

Once the graph is operational, Unfold's `ContentProvider` should be refactored to read from `GraphLookupService` (with DB-backed event data) instead of making relay round-trips for tree traversal. This eliminates the primary latency and reliability bottleneck in Unfold rendering.

---

# 7. Full scope of kinds in phase 1

## 7.1 Kind 30040 — Publication Index (NKBIP-01)

Kind 30040 is used for multiple structural roles in DN:

* **Magazines** — top-level publication indices that contain categories (other 30040s) and/or direct article references (30023, 30041).
* **Categories** — sub-indices within a magazine, themselves 30040 events that contain article/chapter references.
* **Reading lists** — user-created curated lists that also use kind 30040 with `a` tags pointing to articles.

All three share the same kind and the same structural pattern: an event with `a` tags pointing to other coordinates. The graph must handle all of them uniformly. The `entity_type` hint (`magazine`, `category`, `reading_list`) can be derived from structural position (root vs child) or from tag metadata, but the graph traversal logic is identical.

## 7.2 Kind 30041 — Publication Content (NKBIP-01)

Kind 30041 events are AsciiDoc content chapters used in structured publications. A magazine (30040) or category (30040) may reference 30041 coordinates via `a` tags just like it references 30023 articles.

30041 events must be included in the graph as `Record` nodes with `entity_type = 'chapter'`. They participate in the same tree traversal as 30023 articles. Unfold already renders them alongside articles.

## 7.3 Kind 30023 — Long-form Articles (NIP-23)

Articles are the most common leaf nodes in magazine trees. They are parameterized replaceable events with a `d` tag slug.

## 7.4 Kind 30024 — Long-form Drafts (NIP-23)

Drafts are structurally identical to articles but represent unpublished content. They may appear in reading lists or editor-managed structures. Include them in the graph but mark `entity_type = 'draft'`.

---

# 8. Ingestion and sync algorithm

## 8.1 Event ingest pipeline

For every ingested Nostr event:

1. Store raw event in relational tables (including the new `d_tag` column).
2. Parse DN-relevant references into normalized parsed-reference rows (**`a` tags first**, others later).
3. Derive stable record identity for the source event via `RecordIdentityService`.
4. Upsert graph `Record` for that stable identity.
5. Upsert graph `EventVersion` for the concrete event id.
6. Ensure `EventVersion -[:VERSION_OF]-> Record` exists.
7. Determine whether this event is now the current version for the record (highest `created_at` wins; tie-break on lower event id).
8. If it is current:

    * update relational current-record table
    * replace graph `Record -[:CURRENT]-> EventVersion`
    * rebuild outgoing stable `REFERS_TO` edges for that source record
9. If current topology changed, mark transitive ancestors dirty.

---

## 8.2 Rebuild of current outgoing edges

When a source record gets a new current version:

1. remove existing outgoing `REFERS_TO` edges from the source `Record`
2. load normalized parsed references from the new current event
3. for each structural resolvable reference:

    * upsert target `Record` (via `RecordIdentityService`)
    * create new `REFERS_TO` edge with metadata
4. commit in one transaction if practical

This makes the stable topology always reflect current state.

---

## 8.3 Dirty ancestor propagation

When a record's current outgoing topology changes, any ancestor record that reaches it may need recomputation.

At minimum, maintain a dirty queue containing:

* changed `record_uid`
* reason
* created timestamp
* processed timestamp

Ancestor discovery can be done via reverse traversal on `REFERS_TO`.

This does not mean every ancestor must be eagerly rebuilt immediately. It depends on desired freshness.

---

# 9. What counts as a structural edge

Do not indiscriminately graph every tag.

The projection should focus first on **structural relationships relevant to DN traversal**.

Recommended phase 1 scope:

* `a` tags from 30040 events pointing to other 30040, 30041, or 30023 coordinates (magazine/category/chapter/article containment)
* `a` tags from reading list events (also 30040) pointing to article coordinates
* `a` tags from curation sets (30004, 30005, 30006) pointing to content coordinates

Optional later scope:

* `e` tag references (event id links)
* replies
* quote relationships
* reactions
* author follows
* generic mentions

The graph should remain purposeful.

---

# 10. Query patterns the app needs

The implementation should expose service methods roughly equivalent to the following.

## 10.1 Resolve current tree from root record

Input:

* root coordinate or record uid

Output:

* ordered descendant stable records
* ordered current concrete event ids
* optionally path metadata

Used for:

* reader rendering
* magazine page building
* manifest generation
* **Unfold site rendering** (primary consumer)

---

## 10.2 Resolve immediate children of a record

Input:

* source record uid

Output:

* ordered child records
* each child's current event id

Used for:

* page assembly
* editor previews
* debug tooling

---

## 10.3 Find ancestors of a record

Input:

* child record uid

Output:

* all reachable ancestors
* optionally grouped by entity type

Used for:

* invalidation
* "where is this used?" UI
* debugging broken trees

---

## 10.4 Resolve current event version for record

Input:

* coordinate or record uid

Output:

* current event id or null

Used everywhere.

---

## 10.5 Compare two versions of a record's topology

Input:

* old event version id
* new event version id

Output:

* added references
* removed references
* reordered references

Used for:

* diagnostics
* future editorial history features

---

# 11. Suggested service architecture in Symfony

## 11.1 Services

Recommended application services:

### `RecordIdentityService`

**This is the single authority for all identity derivation.** No other code should compute coordinates, record UIDs, or entity types independently.

Responsible for deriving canonical stable identity from a raw event or reference pointer.

Responsibilities:

* normalize coordinates (including the empty/absent `d` tag rule from section 3.1)
* decide `ref_type`
* derive `record_uid`
* derive optional `entity_type`
* provide helpers for decomposing `a` tag values into `(kind, pubkey, d_tag)` tuples

Every service that touches identity (`ReferenceParserService`, `GraphProjectionService`, `CurrentVersionResolver`) must delegate to `RecordIdentityService` for canonical strings.

### `ReferenceParserService`

Responsible for extracting DN-relevant references from event tags/content.

Responsibilities:

* parse `a` tags into normalized references (phase 1 scope)
* classify relation type
* determine structural vs non-structural
* preserve ordering

### `CurrentVersionResolver`

Responsible for determining which event version is current for a replaceable record.

Responsibilities:

* compare `created_at` (highest wins); tie-break on lexicographically lower event id
* update current-record relational table
* expose current event lookup
* skip old revisions — if an incoming event has a `created_at` lower than the known current, no update is needed

### `GraphProjectionService`

Responsible for writing graph nodes/edges.

Responsibilities:

* upsert `Record`
* upsert `EventVersion`
* manage `VERSION_OF`, `CURRENT`, `REFERS_TO`
* rebuild outgoing topology for a record

### `GraphLookupService`

Read-side service used by application features.

Responsibilities:

* resolve descendants
* resolve ancestors
* resolve current tree
* answer dependency queries
* **serve as the primary data source for Unfold's `ContentProvider`**

### `GraphConsistencyService`

Operational service.

Responsibilities:

* audit graph vs relational truth
* rebuild records
* full reindex/reprojection commands

---

## 11.2 Console commands

Recommended commands:

* `dn:graph:rebuild-all`
* `dn:graph:rebuild-record <coord-or-uid>`
* `dn:graph:audit`
* `dn:graph:sync-dirty`
* `dn:graph:backfill-references` — one-time command to populate the `parsed_reference` table from existing events (`a` tags only)

These are important for recovery and confidence.

---

# 12. Storage strategy recommendation

## 12.1 Keep SQL canonical

Use normal SQL tables as the primary operational storage.

The graph extension should be written from parsed normalized rows, not directly from ad hoc application logic spread across controllers.

That keeps behavior deterministic.

---

## 12.2 Prefer stable graph writes

Graph writes should be centered on:

* source record identity
* target record identity
* current version resolution

Avoid deeply coupling graph writes to UI-specific concepts.

---

## 12.3 Full rebuild must be possible

A complete reprojection from relational data must be supported.

That requirement should shape the implementation from the start.

---

# 13. Recommended initial implementation scope

Phase 1 should support:

1. Prerequisite: `d_tag` column on `event` table with `(kind, pubkey, d_tag)` index
2. Prerequisite: resolve `id`/`eventId` duplication on `Event` entity
3. `parsed_reference` table, backfilled from existing events (`a` tags only)
4. `current_record` table with newest-wins rule
5. `30040`-based magazine/category/reading-list current topology
6. `30041` chapter resolution
7. `30023` / `30024` article and draft resolution
8. Record ancestry lookup
9. Record descendant traversal
10. Reverse dependency lookup for invalidation

Once proven:

11. Rewrite Unfold `ContentProvider` to use `GraphLookupService`
12. Deprecate `Magazine` entity and `MagazineProjector`
13. Remove `app:project-magazines` cron and related message/handler

After that, extend to:

* drive/directory-like structures
* manifests
* media groupings
* richer content graphs

---

# 14. Example model for DN magazine trees

## 14.1 Stable records

Example records:

* `30040:<pubkey>:newsroom-magazine`
* `30040:<pubkey>:world`
* `30040:<pubkey>:science`
* `30041:<pubkey>:editorial-note`
* `30023:<pubkey>:article-a`
* `30023:<pubkey>:article-b`

## 14.2 Concrete event versions

Example versions:

* event `m1` is current version of `newsroom-magazine`
* event `c1` is current version of `world`
* event `c2` is current version of `science`
* event `ch1` is current version of `editorial-note`
* event `a7` is current version of `article-a`
* event `b4` is current version of `article-b`

## 14.3 Stable current topology

Graph edges:

* `newsroom-magazine -[:REFERS_TO {relation:"contains", position:1}]-> world`
* `newsroom-magazine -[:REFERS_TO {relation:"contains", position:2}]-> science`
* `newsroom-magazine -[:REFERS_TO {relation:"contains", position:3}]-> editorial-note`
* `world -[:REFERS_TO {relation:"contains", position:1}]-> article-a`
* `science -[:REFERS_TO {relation:"contains", position:1}]-> article-b`

At render time, the app traverses records, then resolves `CURRENT` to event ids.

That is the point of the design.

---

# 15. Failure modes and safeguards

## 15.1 Stale current edges

Problem:
A record has multiple possible current versions or an outdated current edge.

Safeguard:

* enforce one current row in relational table (unique on `coord`)
* graph `CURRENT` edge should be rewritten from relational truth
* add audit command

## 15.2 Missing target record

Problem:
A current event points to a coordinate that has not yet been ingested as an event.

Safeguard:

* create placeholder `Record` node from the reference alone
* allow missing `CURRENT`
* traversal should tolerate unresolved descendants

## 15.3 Broken rebuild logic

Problem:
Graph drift after partial failure.

Safeguard:

* every graph update should be idempotent
* support single-record rebuild
* support full reprojection

## 15.4 Over-graphing

Problem:
The graph becomes noisy and expensive because every tag becomes an edge.

Safeguard:

* graph only structural relationships in phase 1 (`a` tags)
* keep broader relations optional

---

# 16. Indexing and performance notes

Even with graph support, relational indexes still matter.

Ensure strong indexes on:

* `event.id` (already PK)
* `event.(kind, pubkey, d_tag)` — the canonical coordinate index (prerequisite 0.1)
* `event.(kind, created_at)` — for listing queries
* `parsed_reference.(source_event_id)` — for forward lookup
* `parsed_reference.(target_coord)` — for reverse lookup
* `parsed_reference.(target_kind, target_pubkey, target_d_tag)` — for flexible target lookup
* `current_record.(coord)` and `current_record.(record_uid)` — unique

Graph traversal should be used where it beats recursive SQL and dependency walks.
Simple listings should stay in SQL.

---

# 17. AI-agent implementation plan

An AI agent implementing this should proceed in this order.

## Phase 0: relational prerequisites

1. Add `d_tag` column to `event` table with backfill migration.
2. Add `(kind, pubkey, d_tag)` composite index.
3. Resolve `id`/`eventId` duplication on `Event` entity.
4. Implement `RecordIdentityService` with canonical coordinate normalization (including empty/absent `d` tag rule).

## Phase 1: relational groundwork

1. Introduce `parsed_reference` table with migration.
2. Build `ReferenceParserService` for `a` tag extraction.
3. Backfill `parsed_reference` from existing events (`a` tags only).
4. Introduce `current_record` table with migration.
5. Build `CurrentVersionResolver` with newest-wins rule.
6. Populate `current_record` from existing events (one pass, skip old revisions).

## Phase 2: graph schema bootstrap

1. Create graph namespace/graph (Apache AGE).
2. Add node/edge creation helpers.
3. Implement idempotent upsert routines for `Record` and `EventVersion`.
4. Implement edge maintenance for `VERSION_OF` and `CURRENT`.

## Phase 3: current topology projection

1. Build `REFERS_TO` from current version only.
2. Support ordered child edges.
3. Implement single-record rebuild.
4. Implement full graph rebuild command.
5. Implement `dn:graph:backfill-references` command.

## Phase 4: read-side services

1. Resolve descendants from root record.
2. Resolve current event ids for descendants.
3. Resolve ancestors of a record.
4. Expose reverse dependency lookup.
5. Refactor Unfold `ContentProvider` to use `GraphLookupService`.

## Phase 5: Magazine deprecation

1. Verify graph produces identical tree results to `MagazineProjector`.
2. Rewrite remaining `Magazine` consumers to use `GraphLookupService`.
3. Deprecate `MagazineProjector`, `ProjectMagazineMessage`, `ProjectMagazineMessageHandler`.
4. Remove `app:project-magazines` cron entry.
5. Deprecate `Magazine` entity. Keep table for rollback, then drop.

## Phase 6: operational hardening

1. Add dirty queue.
2. Add audit checks.
3. Add tests for replaceable updates.
4. Add metrics/logging around graph rebuilds and drift.

---

# 18. Acceptance criteria

The implementation is acceptable when all of the following are true.

1. The `event` table has a `d_tag` column with a `(kind, pubkey, d_tag)` composite index.
2. The `Event` entity has no `eventId` column — only `id`.
3. The `parsed_reference` table is populated for all existing events' `a` tags.
4. Given a magazine coordinate, the app can return the ordered current concrete event ids of all current linked content in its tree (including 30041 chapters).
5. Updating a category, chapter, or article to a new current version updates traversal results without manual patching.
6. Given an article coordinate, the app can answer which current categories/magazines include it.
7. A missing target coordinate does not crash traversal.
8. A single-record graph rebuild restores correct topology for that record.
9. A full graph reprojection restores the graph from relational data only.
10. Tests cover parameterized replaceable current-version changes.
11. Unfold can render a magazine site using `GraphLookupService` without relay round-trips for tree traversal.
12. The `Magazine` entity and `MagazineProjector` are deprecated and no longer needed for any runtime feature.

---

# 19. Recommended implementation stance

The right mental model is this:

**Postgres stores the facts. The graph stores the navigable shape of those facts.**

DN needs both because the difficult part is not rows. It is the moving topology created by coordinates, current versions, and nested references.

---

# 20. Practical recommendation for phase 1

Implement this first around magazine/category/chapter/article traversal only, covering kinds 30040, 30041, 30023, and 30024.

Do not wait for a universal graph ontology.

If the model works for:

* `30040` current hierarchy (magazines, categories, reading lists)
* `30041` chapter resolution
* `30023` article resolution
* ancestry lookup
* dependency invalidation
* **Unfold rendering without relay round-trips**

then it is already proving its value and can be extended to drives, manifests, and other DN structures.

---

# 21. Minimal pseudocode for projection flow

```text
onEventIngest(event):
  storeRawEvent(event)                              // includes d_tag extraction
  refs = parseATagReferences(event)                  // a tags only in phase 1
  storeToReferencesTable(event.id, refs)
  sourceRecord = RecordIdentityService.derive(event) // single authority

  graph.upsertRecord(sourceRecord)
  graph.upsertEventVersion(event)
  graph.linkVersionOf(event, sourceRecord)

  becameCurrent = currentResolver.updateIfCurrent(sourceRecord, event)
  // newest created_at wins; skip if older than known current

  if becameCurrent:
    graph.replaceCurrentEdge(sourceRecord, event)
    graph.replaceOutgoingStableEdges(sourceRecord, refs)
    dirtyQueue.markAncestorsOf(sourceRecord)
```

---

# 22. Agent implementation feasibility and required permissions

## 22.1 What an agent can do independently

The majority of this plan is standard Symfony/Doctrine/PHP work that an agent can execute without special permissions:

| Task | Agent-independent? | Notes |
|------|--------------------|-------|
| Add `d_tag` column (migration) | ✅ Yes | Standard Doctrine migration |
| Backfill `d_tag` from JSONB tags | ✅ Yes | SQL `UPDATE` in migration |
| Remove `eventId` duplication | ✅ Yes | Entity refactor + migration |
| Create `parsed_reference` table | ✅ Yes | Standard Doctrine migration |
| Backfill `parsed_reference` (`a` tags) | ✅ Yes | SQL or console command |
| Create `current_record` table | ✅ Yes | Standard Doctrine migration |
| Build `RecordIdentityService` | ✅ Yes | Pure PHP service |
| Build `ReferenceParserService` | ✅ Yes | Pure PHP service |
| Build `CurrentVersionResolver` | ✅ Yes | PHP service + SQL |
| Build `GraphProjectionService` | ⚠️ Partial | Depends on AGE being installed |
| Build `GraphLookupService` | ⚠️ Partial | Depends on AGE being installed |
| Build console commands | ✅ Yes | Standard Symfony commands |
| Deprecate `Magazine` entity | ✅ Yes | Code removal + migration |
| Refactor Unfold `ContentProvider` | ✅ Yes | PHP refactor |
| Write tests | ✅ Yes | PHPUnit, no special access |

**Phase 0 and Phase 1 are fully agent-executable.** These are standard Symfony development tasks: writing migrations, PHP services, and console commands. An agent with Docker exec access can write the code, generate migrations, and run them.

**Phase 2 onward requires Apache AGE to be installed**, which is an infrastructure prerequisite that needs human decision and action.

## 22.2 What requires human permission or action

### A. Installing Apache AGE in PostgreSQL — ❌ Cannot be done by agent alone

Apache AGE is a PostgreSQL extension that must be compiled and installed into the database server. The current setup uses `postgres:17-alpine` as the Docker image.

AGE is **not available** in the stock `postgres:17-alpine` image. Options:

1. **Switch to the official AGE Docker image** (`apache/age:PG17_latest` or similar). This changes the `database` service image in `compose.yaml` and `compose.prod.yaml`.
2. **Build a custom Postgres image** that installs AGE from source on top of `postgres:17-alpine`.
3. **Use `apt-get install` in an init script** if a Debian-based Postgres image is used instead of Alpine.

All three options change the Docker infrastructure and require testing against the production database.

**Required human decisions:**

* Choose the AGE installation method.
* Approve the Docker image change for both dev and prod.
* Test that existing PostgreSQL data and migrations still work with the new image.
* Run `CREATE EXTENSION age;` and `LOAD 'age';` as a superuser (the application DB user may not have `CREATE EXTENSION` privileges, especially in production).

**Suggested approach:** switch the database image to `apache/age:PG17_latest` in both compose files. This is the simplest path. A migration can then run `CREATE EXTENSION IF NOT EXISTS age;` and `SET search_path = ag_catalog, "$user", public;`.

### B. PostgreSQL superuser access — ❌ Required for AGE setup

The `CREATE EXTENSION` command requires superuser or `pg_admin` privileges. The current app user (`${POSTGRES_USER:-app}`) may not have these. Depending on the hosting setup:

* **Dev:** The app user is typically the database owner and can create extensions. ✅ Likely fine.
* **Prod:** The app user may be restricted. A DBA or manual step would be needed to run `CREATE EXTENSION age;` once. After that, the agent can use AGE normally from the app user.

### C. Production deployment — ❌ Requires human oversight

* The `d_tag` backfill migration will `UPDATE` every parameterized replaceable event in the `event` table. On a large table this could be slow and should be run during low-traffic periods.
* The `eventId` column removal is a breaking change — all running app instances must be on the new code before the column is dropped. This requires a coordinated deployment (deprecation release first, then column-drop release).
* The AGE extension installation on prod requires a database restart or at minimum a connection reload.

### D. Dependency on a PHP AGE client library — ⚠️ Agent can work around

There is **no mature Composer package** for Apache AGE in PHP as of early 2026. The agent will need to:

* Use raw SQL with `ag_catalog.cypher()` function calls via Doctrine DBAL.
* Wrap Cypher queries in a thin service layer (`GraphProjectionService`, `GraphLookupService`).
* This is entirely doable — AGE queries are just SQL function calls — but there's no ORM or query builder support. The agent writes raw Cypher strings.

Example of how AGE queries work from PHP/DBAL:

```php
$conn->executeStatement("SELECT * FROM ag_catalog.cypher('dn_graph', $$ 
  MATCH (r:Record {coord: $coord})-[:REFERS_TO*]->(child:Record) 
  RETURN child.coord, child.entity_type 
$$) AS (coord agtype, entity_type agtype)", ['coord' => $coordinate]);
```

This is not blocked — just unconventional for a Symfony app.

## 22.3 Recommended sequencing

```
 Phase 0  ─────────────────────────────────────────── Agent alone
   d_tag column, eventId cleanup, RecordIdentityService
   
 Phase 1  ─────────────────────────────────────────── Agent alone
   parsed_reference, current_record, backfill commands
   
 ── HUMAN CHECKPOINT ──────────────────────────────── 
   • Approve Docker image change (postgres → apache/age)
   • Approve compose.yaml + compose.prod.yaml changes
   • Run CREATE EXTENSION age on prod database
   
 Phase 2  ─────────────────────────────────────────── Agent alone (after AGE available)
   Graph schema, node/edge helpers, Cypher wrappers
   
 Phase 3–6 ────────────────────────────────────────── Agent alone
   Topology projection, read services, Magazine deprecation, hardening
```

**In summary:** Phases 0–1 (approximately 60% of the total work) can proceed immediately with no special permissions. Phases 2–6 are blocked on one infrastructure decision: switching the PostgreSQL Docker image to include Apache AGE and running `CREATE EXTENSION`. Once that gate is cleared, the remaining work is again fully agent-executable.

---

# 23. One-line implementation principle

**Model stable identities for traversal, concrete event versions for realization, and keep the graph as a rebuildable projection over canonical relational data.**

