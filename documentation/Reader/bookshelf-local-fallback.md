# Bookshelf Local Relay and API Fallback

The separate directory-reference disclosure uses the shared native lazy shell in
My Books and, once per page, Reading Nook. Both use the same local-only owner
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


When a reader opens a Nostr-native book, its kind `30040` index is used to collect kind `30041` chapter references and relay hints. Missing chapters are fetched from those hints first; any unresolved chapter identifiers then fan out to the chapter author's regular relays.
## Flow

1. An authenticated reader opens `/bookshelf/my-books`.
2. `BookshelfDirectoryRefreshService` asks the configured local relay for `30045:<pubkey>:my-book-collection`.
3. A returned event is persisted through `GenericEventProjector`; its replaceable-event checks retain only the current revision.
4. `BookshelfDirectoryService` reads the resulting local directory and extracts book references.
5. `MercuryBookService` resolves the references through the bundle's Books API and Mercury clients, merges duplicate coordinates, and restores directory order.
6. `BookshelfRelayBookLoader` refreshes unpinned coordinates and retrieves missing exact references from regular relays, then merges them into the same order.

## Book detail navigation

Book detail pages at `/bookshelf/book/{slug}` also link directly to the
continuous reader for the displayed book's exact event ID. Chapter links remain
available. A "Contained in index" section lists locally known current kind
`30040` indexes referencing that book coordinate, excluding mismatched version
pins, hidden/deleted indexes, suppressed or reader-muted authors, and sensitive
metadata. Parent discovery inspects at most the 50 newest local referencing
events (lowest event ID first on timestamp ties), rather than the oldest; no
remote discovery is added. Links retain the parent's author-qualified NIP-19
address.

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
editable tags as the ordered source. Targets remain explicitly unchecked: no
Books API, Mercury, relay, body conversion, or persistence is triggered by the
fragment. The Load link also works without JavaScript. Nested-directory or
book-content previews are not part of this slice.

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
