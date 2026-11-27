# Bookshelf Local Relay and API Fallback

The separate directory-reference disclosure uses the shared native lazy shell in
My Books and, at most once per page beside drives, Reading Nook. Both use the same local-only owner
endpoint. `EVENT_WORKSPACE_EXPANSION_ENABLED` defaults to `true`; setting it to
`false` hides these disclosures and returns 404 from
`/bookshelf/my-books/references`, without gating My Books, reader, or directory
publish/removal routes. It does not change freshness or containing-index lookup.
See the [workspace iteration plan](event-workspace-iteration-plan.md#shared-step-2-disclosure-shell-and-rollback)
for rollback and remaining Step 3+ deferrals.

`/bookshelf/my-books` refreshes the signed My Books directory from the instance's local Nostr relay before rendering. The BookshelfBundle resolves saved publication references through the Decent Newsroom Books API and Mercury.

## Overview

The My Books list is a parameterized replaceable kind `30045` event with the stable `d` tag `my-book-collection`. A browser may publish a newer copy through another client or relay, so a database-only lookup can be stale. The page probes the configured local relay on each My Books load and projects a returned event through the normal generic projector. NIP-01 replaceable-event ordering keeps the newest revision and ignores stale relay copies.

The bundle queries both sources and merges their results by publication coordinate. For duplicate replaceable publications, the newest `createdAt` revision wins. The merged list retains the directory's declared order, so books found by only one source still appear in the expected position. A transport or HTTP failure from either source does not discard results returned by the other source.

If the bundle's Books API and Mercury resolution both fail, My Books logs the
failure and displays its unavailable notice. It does not bypass the REST API
through a direct Elasticsearch query.

`BookshelfRelayBookLoader` revalidates unpinned kind `30040` coordinates even
when the Books API or Mercury already returned a book. The batched lookup checks
the local relay and directory relay hints, then checks the author's regular
relays even if a local revision exists. The newest `created_at` wins; equal
timestamps prefer the lexicographically lowest event ID. Older relay copies
cannot replace newer indexed books. An unavailable refresh logs a warning and
retains the indexed result.

Explicit `e` references and `a[3]` version pins remain exact: only missing pinned
IDs are fetched, a pinned `a` result must also match its coordinate, and a
missing pin never silently falls back to another revision. Saving a book with a
version pin therefore intentionally keeps that saved revision. Refresh does not
rewrite the signed directory or change exact-ID reader URLs.


When a reader opens a book, the continuous reader obtains its exact kind
`30040` source from local storage, the Books API/Mercury or relays. The reader
displays direct kind `30040` and `30041` references in
signed tag order. Nested indexes start collapsed, using their signed identifier
as the label; opening one loads its own body and direct children. Each loaded
index has its own table of contents. Repeated references remain separate
occurrences. Missing or invalid references stay visible as unavailable;
version pins are resolved only by their exact event IDs and must match the
referenced coordinate. The reader uses local events first, then the Books API,
Mercury and relay fallback only for unresolved targets. All books skip the
legacy separate chapter-loading pass. Unpinned API lookups select one exact
nonblank coordinate per call through the Books API, which orders revisions by
newest timestamp and then lowest event ID.
They do not use Mercury's ambiguous equal-time ordering or combine multiple
authors and identifiers. Exact-ID lookups retain both APIs. Blank identifiers
bypass APIs that strip them and use exact-coordinate relay lookup, never an
author-only API query. Metadata-only indexes can still render a reader shell.
Each level uses at most five relay hints and falls back for at most
20 distinct author/kind groups; additional unresolved references stay visible
as unavailable.

Each reader index is paged in batches of five, up to 500 occurrences, and
traversal is limited to six levels. Cycles are detected on the current path, so a
shared index can still appear elsewhere. Each fragment re-resolves its occurrence path
from the exact root and rechecks every ancestor; browser-supplied paths do not
grant access. Nested
indexes and sections are checked for reader mutes, author suppression,
hidden coordinates, deletion, scope and sensitive tags before their metadata
or contents are exposed; blocked references remain unavailable and are not
expanded. The response is private and not cached. If the source index cannot
be obtained, the reader returns an explicit unavailable page with a retry link
and logs a warning, rather than retrying an eager whole-book chapter fetch.

## Flow

1. An authenticated reader opens `/bookshelf/my-books`.
2. `BookshelfDirectoryRefreshService` asks the configured local relay for `30045:<pubkey>:my-book-collection`.
3. A returned event is persisted through `GenericEventProjector`; its replaceable-event checks retain only the current revision.
4. `BookshelfDirectoryService` reads the resulting local directory and extracts book references.
5. `MercuryBookService` resolves the references through the bundle's Books API and Mercury clients, merges duplicate coordinates, and restores directory order.
6. `BookshelfRelayBookLoader` refreshes unpinned coordinates and retrieves missing exact references from regular relays, then merges them into the same order.

## Asynchronous book loading

The initial reader and public index pages render a shell rather than resolving
their entire table of contents inline. Turbo loads the root contents separately.
Reader fragments resolve and render at most five direct occurrences; public
index TOC fragments retain their 50-occurrence local-metadata pages.
Nested indexes remain collapsed and request their own contents only when opened;
loading one index does not recursively load its descendants. Pagination stays
inside the corresponding contents frame instead of replacing the book page.
Nested pagination carries HMAC-bound occurrence paths and exact resolved index
revisions so later pages cannot silently switch to a newer index revision.

These are separate HTTP requests, not a new Messenger ingestion pipeline.
Every fragment still checks its source and traversal access independently.
Signed order, duplicate occurrences, exact version pins, and unavailable entries
are retained. Public index TOCs remain local-only; the continuous reader still
acquires its exact source from local storage, the APIs, or relays.

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
available. A "Contained in index" section lists locally known current kind
`30040` indexes referencing that book coordinate, excluding mismatched version
pins, hidden/deleted indexes, suppressed or reader-muted authors, and sensitive
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

Every page/fragment allows only locally stored public kind `30040` Events,
checking hidden coordinates, reader mutes, author suppression, and NIP-09
deletion before exposing source data. Signed fragment ancestry is also
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
The normal book cards and their existing resolution pipeline are unchanged.

## Configuration

| Parameter / environment variable | Default | Description |
|---|---|---|
| `NOSTR_DEFAULT_RELAY` | `ws://strfry:7777` in Docker | Local relay checked for the current directory |

## Limitations

- If both Mercury and the Decent Newsroom Books API are unavailable, My Books logs
  the failure and displays its normal availability notice.
- The relay refresh is best-effort: an unavailable relay leaves the locally projected directory intact.

## Related NIPs / NKBIPs

- [NKBIP-04](../NKBIP/04.md) — kind `30045` filesystem directory event used for My Books.
