# Bookshelf Local Relay and API Fallback

`/bookshelf/my-books` refreshes the signed My Books directory from the instance's local Nostr relay before rendering. Saved publication references are resolved through both the external Mercury service and this application's read-only Books API.

## Overview

The My Books list is a parameterized replaceable kind `30045` event with the stable `d` tag `my-book-collection`. A browser may publish a newer copy through another client or relay, so a database-only lookup can be stale. The page probes the configured local relay on each My Books load and projects a returned event through the normal generic projector. NIP-01 replaceable-event ordering keeps the newest revision and ignores stale relay copies.

The book resolver queries Mercury and the local Books API, then merges their results by publication coordinate. For duplicate replaceable publications, the newest `createdAt` revision wins. The merged list retains the directory's declared order, so books found by only one source still appear in the expected position. A transport or HTTP failure from either source does not discard results returned by the other source. Both paths use the bundle's same event-to-book mapping, preserving missing-item handling.
The public Books API deliberately returns bare event arrays and objects. `BooksApiMercuryHttpClient` adapts only the internal fallback response to Mercury's `data` envelope and constrains internal event-filter requests to the API's 100-result maximum, leaving the public API contract unchanged.

If both HTTP sources fail, My Books queries the configured Books Elasticsearch alias directly through `BookshelfEsBookLoader`; the unavailable notice appears only when that final lookup is unavailable too.

For references still unresolved after those indexed sources, `BookshelfRelayBookLoader` fetches the kind `30040` publication directly using the normal Nostr coordinate and event-ID lookups. Those lookups check the local relay, any relay hints in the directory tag, and the publication author's regular relays. Relay-resolved books are merged into their declared directory positions.


When a reader opens a Nostr-native book, its kind `30040` index is used to collect kind `30041` chapter references and relay hints. Missing chapters are fetched from those hints first; any unresolved chapter identifiers then fan out to the chapter author's regular relays.
## Flow

1. An authenticated reader opens `/bookshelf/my-books`.
2. `BookshelfDirectoryRefreshService` asks the configured local relay for `30045:<pubkey>:my-book-collection`.
3. A returned event is persisted through `GenericEventProjector`; its replaceable-event checks retain only the current revision.
4. `BookshelfDirectoryService` reads the resulting local directory and extracts book references.
5. `BookshelfBookLoader` resolves the references through Mercury and the local Books API, merges duplicate coordinates, and restores directory order.
6. `BookshelfRelayBookLoader` retrieves remaining Nostr-original publications from regular relays and merges them into the same order.

## Configuration

| Parameter / environment variable | Default | Description |
|---|---|---|
| `BOOKS_LOCAL_API_BASE_URL` | `http://php/books` | Internal base URL for the local Books API fallback |
| `NOSTR_DEFAULT_RELAY` | `ws://strfry:7777` in Docker | Local relay checked for the current directory |

## Limitations

- If both Mercury and the local Books API are unavailable, My Books displays its normal availability notice.
- The relay refresh is best-effort: an unavailable relay leaves the locally projected directory intact.

## Related NIPs / NKBIPs

- [NKBIP-04](../NKBIP/NKBIP-04.md) — kind `30045` filesystem directory event used for My Books.
