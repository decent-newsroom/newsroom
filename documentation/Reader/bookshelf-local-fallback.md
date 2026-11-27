# Bookshelf Local Relay and API Fallback

The separate directory-reference disclosure uses the shared native lazy shell in
My Books and, at most once per page beside drives, Reading Nook. Both use the same local-only owner
endpoint. `EVENT_WORKSPACE_EXPANSION_ENABLED` defaults to `true`; setting it to
`false` hides these disclosures and returns 404 from
`/bookshelf/my-books/references`, without gating My Books, reader, or directory
publish/removal routes. It does not change freshness or containing-index lookup.
See the [workspace iteration plan](event-workspace-iteration-plan.md#shared-step-2-disclosure-shell-and-rollback)
for rollback and remaining Step 3+ deferrals.

`/bookshelf/my-books` reads the last locally synced or published copy of the signed directory and resolves one page of saved books at a time.

## Overview

The My Books list is a parameterized replaceable kind `30045` event with the
stable `d` tag `my-book-collection`. Publishing and the existing login sync
update the local directory. Opening the page reads that copy. A directory
published elsewhere appears after the instance syncs it.

My Books resolves up to 10 references per page in signed order. The Decent
Newsroom Books API receives the page lookup first. Mercury receives the
remaining references, and the configured local strfry relay receives one
subscription for any remaining IDs and coordinates. Each HTTP filter has a
two-second budget and a single attempt. Successful matches survive a later
provider failure.

Explicit `e` references and `a[3]` version pins stay bound to their exact event
IDs. A pinned address result must also match its coordinate. Unpinned
coordinates use the first provider that resolves them. When a provider returns
multiple candidates, the newest timestamp wins, with the lowest event ID as
the tiebreaker. This keeps refresh work off the page at the cost of seeing a
newer unpinned revision only once an earlier provider indexes it or stops
returning the older revision.


When a reader opens a book, the continuous reader obtains its exact kind
`30040` source from local storage, the Books API/Mercury or relays. The reader
displays direct kind `30040` and `30041` references in
signed tag order. Nested indexes start collapsed, using their signed identifier
as the label; opening one loads its direct-reference outline. Its body and
chapter bodies load independently. Each loaded
index has its own table of contents. Repeated references remain separate
occurrences. Missing or invalid references stay visible as unavailable;
version pins are resolved only by their exact event IDs and must match the
referenced coordinate. Root and pinned event reads start with the exact-ID
cache. Cold exact IDs and unpinned targets can use local storage, the Books API,
Mercury and the reader's relay fallback. Unpinned API lookups select one exact
nonblank coordinate per call through the Books API, which orders revisions by
newest timestamp and then lowest event ID.
They do not use Mercury's ambiguous equal-time ordering or combine multiple
authors and identifiers. Exact-ID lookups retain both APIs. Blank identifiers
bypass APIs that strip them and use exact-coordinate relay lookup, never an
author-only API query. Metadata-only indexes can still render a reader shell.
Each level uses at most five relay hints and falls back for at most
20 distinct author/kind groups; additional unresolved references stay visible
as unavailable.

Each reader index lists up to 500 direct occurrences without fetching child
events. Traversal is limited to six levels. Cycles are detected on the current
path, so a shared index can still appear elsewhere. Each fragment re-resolves its
occurrence path from the exact root and rechecks every ancestor. The reader checks
author suppression, hidden coordinates, scope, and sensitive tags before exposing
metadata or contents. Blocked references stay unavailable. The response is
private and no-store. If the source index cannot
be obtained, the reader returns an explicit unavailable page with a retry link
and logs a warning, rather than retrying an eager whole-book chapter fetch.

The continuous reader, public Bookshelf indexes and chapters, containing-index
links, and featured Bookshelf views skip read-time NIP-51 viewer-mute and NIP-09
deletion lookups. A muted author's book or a deleted revision that is still
available from a source or cache can appear in these views. Ingestion still
applies NIP-09. The private directory-reference disclosure, linked article
previews, and Reading Nook retain their own access policies.

## Flow

1. An authenticated reader opens `/bookshelf/my-books`.
2. `BookshelfDirectoryService` reads the signed directory from local storage and extracts book references.
3. The controller slices the visible page before requesting publication metadata.
4. The resolver tries the Books API, Mercury, and local strfry in order, passing unresolved references to each successive source.

## Asynchronous book loading

The continuous reader renders its TOC and ordered chapter placeholders inline
from the authorized source's signed tags. It does not wait for chapter retrieval
or conversion. TOC links are local anchors with Turbo navigation disabled, so
clicking one scrolls to the existing occurrence rather than requesting the TOC
again.

Each chapter uses a Turbo frame backed by `/bookshelf/{id}/chapter/{position}`.
Only direct chapters among the first two entries in an index start immediately.
After those requests settle, the reader starts later chapters one at a time as
their placeholders enter the viewport. Each request resolves and converts one chapter
and shares the workspace's four-request browser concurrency limit. The queue
releases slots through Turbo's completion and failure lifecycle. Turbo reads
the chapter response for rendering. Placeholders show a skeleton while waiting,
and failed frame requests offer a retry.

Nested indexes load when opened via `/bookshelf/{id}/contents` with their signed
occurrence path, then apply the same two-entry starting window. An optional
introductory body loads in the viewport through its own `/index-body` request.

Chapter signatures bind the exact root, occurrence position, ancestry, and
resolved ancestor revisions in a separate HMAC domain. Every chapter request
rechecks source and target access; siblings remain mounted if one fails.
Nested requests retain exact ancestor revisions rather than silently switching
to a newer index. No recursive child-index retrieval occurs.

The public index viewer remains distinct: its local-metadata TOC loads through
`/bookshelf/index/{eventId}/contents`, with 50 occurrences per page. It does
not load chapter bodies.

These are separate HTTP requests, not a new Messenger ingestion pipeline.
Every fragment still checks its source and traversal access independently.
Signed order, duplicate occurrences, exact version pins, and unavailable entries
are retained. Public index TOCs remain local-only; the continuous reader still
acquires its exact source from local storage, the APIs, or relays.

### Reader cache

The continuous reader uses Symfony's persistent filesystem-backed `cache.app`
pool, shared across requests and users, under versioned `bookshelf_reader_v1_`
keys. Exact event snapshots, parsed references, and tag-only outlines are
retained for one year. Successful chapter/index-body conversion is cached for one year by exact
event ID and content hash, independently of the occurrence-specific Turbo frame.
Repeated or shared occurrences reuse that conversion without sharing DOM IDs.

Unpinned coordinate lookups reuse their successful resolved event for 30
days so later revisions can be discovered. Local database revisions take
precedence over cached coordinate results. Exact version pins use only their
exact cached event, never the unpinned coordinate mapping. Missing targets and
conversion failures are not cached, so a retry can recover immediately.

The cache stores data and rendered bodies, not authorization decisions or whole
HTTP responses. Each request verifies signatures and ancestor relationships and
checks author suppression, hidden coordinates, scope, and sensitivity before
using cached content. On a warm chapter traversal, author and coordinate checks
run in one batch. A cold nested index can require checking the traversed path
before looking up the next index. Fragment responses remain private/no-store so a
browser cache cannot bypass those checks. Cache read/write failures are logged;
normal resolution and rendering remain available. Clearing `cache.app` also
clears the reader cache.

## Continuous reader layout

The continuous reader and chapter list use shrinkable single-column grid tracks
in `assets/styles/04-pages/bookshelf.css`. Grid items can shrink within the
application shell's main column; long titles, metadata and links wrap without
expanding it. Chapters retain their 76ch reading measure. Images and embedded
media are capped to the chapter width, while wide code blocks, tables and display
math scroll locally. The chapter body also provides horizontal scrolling for
other oversized rendered content instead of clipping it or widening the viewport.

## Book detail navigation

Book detail pages at `/bookshelf/book/{slug}` also link directly to the
continuous reader for the displayed book's exact event ID. Chapter links remain
available. A lazy "Contained in index" section loads through
`/bookshelf/index/{eventId}/containing-indexes` when its frame enters the
viewport. It lists locally known current kind
`30040` indexes referencing that book coordinate, excluding mismatched version
pins, hidden indexes, suppressed authors, and sensitive
metadata. The same section appears on `/bookshelf/index/{eventId}` so readers
can navigate up through locally known parent indexes. It uses the displayed
index's exact event ID when checking version pins, and is omitted when no
readable parent is found. Parent discovery inspects at most the 50 newest local
referencing events (lowest event ID first on timestamp ties) and checks each
parent's current local revision; links removed from that revision are not shown.
No remote discovery is added. Links open `/bookshelf/index/{eventId}` with the
displayed parent's exact local revision, not the generic NIP-19 route (which may
classify an index as a magazine). Same-slug authors and old pinned revisions
remain distinct. Existing magazine classification/routes and slug-based book
detail chapter/actions are unchanged.

This public Bookshelf index viewer shows the tag-derived publication title as
the page heading and links its author to their profile, using locally stored
kind `0` metadata or cached profile data for the display name and a shortened
identifier when unavailable. Sensitive titles remain hidden. It composes the
shared tag-only event preview and ordered TOC, fetched independently through
`/bookshelf/index/{eventId}/contents`. Books retain the exact-ID
continuous-reader link. TOC headings distinguish Article-only leaves,
chapter-only leaves, and mixed or other content. Each TOC page resolves at
most 50 visible local occurrences. Known, readable indexes and articles show
their signed title and summary without reference labels, coordinates, pins, or
revision identifiers; an untitled or sensitive index uses the corresponding
generic label. Unresolved or restricted references retain their reference
details. Nested indexes have a
Bookshelf detail link and an explicit lazy disclosure using
`/bookshelf/index/{eventId}/toc`, never the owner-only Reading Nook endpoints.
When the signed index has exactly one kind `30023` content occurrence and at
least one kind `30040` occurrence, with no other or malformed content
addresses, Bookshelf separates that article reference above an Indexes section.
This also applies inside nested Bookshelf index disclosures. The featured
reference appears on each page, with at most 49 index occurrences below it,
so the 50-occurrence limit remains intact even when the article occurs later in
the signed tags. Non-content tags do not affect the split. Original source
positions, remaining index order, duplicates, unresolved references, raw pins,
and full coordinates are preserved in the underlying occurrences; an invalid
pin stays visible as invalid.
Other TOCs, including Reading Nook, retain their original ordering and 50-row
pages. Exact pins never fall back to latest or a different author.

Every page/fragment allows only locally stored public kind `30040` events,
checking hidden coordinates and author suppression before exposing source data.
Signed fragment ancestry is also
re-authorized on each request. Author metadata is read locally. Article metadata
is shown only when the exact local Event has a matching Article projection and
passes the existing entitlement, scope, sensitivity, author, mute, hidden
coordinate, and deletion checks. Missing or mismatched projections and cold
pins remain references; they never fall back to another revision. No draft
lookup, relay or profile fetch, body conversion, or write occurs.
Sensitive preview metadata stays
gated by the shared preview/TOC primitives. Responses are private/no-store.
Nested state uses the existing HMAC traversal signer, occurrence-specific
frame IDs, path-local cycle detection, six-level depth limit, and 50-row
pagination. The expansion rollback flag hides lazy controls and disables the
nested fragment endpoint, not the navigable detail pages. Local misses remain
unavailable; this viewer does not add remote discovery or a new book reader.

The Bookshelf landing page mounts the configured featured preview in its own
viewport-loaded `/bookshelf/featured/preview` frame. Active searches display
search results without the featured request. The full featured directory is
available at `/bookshelf/featured` and retains exact-revision pagination.

## Ordered directory disclosure

My Books also has a separate native disclosure for the raw signed directory's
`a` and `e` reference occurrences. It does not replace the existing book cards,
Open/Remove actions, directory editing, or the page's normal refresh and
resolution behavior.

The lazy frame loads `/bookshelf/my-books/references` from the local database
only. Its first request selects the current owner's latest locally stored kind
`30045` event with `d=my-book-collection` (newest timestamp, lowest event ID on
ties). Pagination carries that exact source event ID and displays at most 50
ordered occurrences, preserving source positions, duplicates, malformed entries,
coordinates, exact `e` IDs, and NKBIP-04 `a`-tag version pins. The fourth slot of
an `e` tag is not interpreted as a version pin.

Each fragment requires `ROLE_USER`, rechecks the owner, source kind and signed
directory identifier, author suppression, hidden coordinate, and NIP-09 deletion,
and returns `Cache-Control: private, no-store`. Without a local signed directory
the disclosure shows an empty state; no synthetic event is created.

This view uses `BookshelfReferencePage`, `EventWorkspaceSourceAdapter`, and the
shared presentation factory. It never uses the bundle's normalized/deduplicated
editable tags as the ordered source. Visible targets now use the same
`TargetPreviewResolver` and escaped, non-clickable metadata partial as
[Reading Nook bookmarks](reading-nook.md#shared-local-target-previews).
The DB-only target query is bounded to this page, preserves exact `e` IDs and
directory `a[3]` pins, and selects latest unpinned `a` revisions with a lowest-ID
tie-break. Missing or mismatched pins never fall back to latest.

Target author suppression, NIP-09 deletion, hidden coordinates, and strict
reader mutes are batch-checked. Only public kinds `30040`, `30041`, `1`, `20`,
`21`, and `22` may expose kind and a bounded title; sensitive titles are hidden.
Drafts, private kinds, and kind `30023` articles without safely established
Article/member authorization stay restricted, with no target metadata.
Missing and restricted states are explicit. No Article projection is required
for supported kinds, and no body/signature columns or media are loaded.

No Books API, Mercury, relay, body conversion, persistence, or async work is
triggered by the fragment. The Load link also works without JavaScript.
Nested-directory traversal and book-content rendering remain out of scope.

## Configuration

| Parameter / environment variable | Default | Description |
|---|---|---|
| `NOSTR_DEFAULT_RELAY` | `ws://strfry:7777` in Docker | Local relay fallback for unresolved My Books publication references |

## Limitations

- The saved directory reflects the most recent local publish or sync. An unavailable publication source leaves the directory intact, and successfully resolved cards remain visible.

## Related NIPs / NKBIPs

- [NKBIP-04](../NKBIP/04.md) — kind `30045` filesystem directory event used for My Books.
