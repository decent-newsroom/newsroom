# Bookshelf Local Relay and API Fallback

`/bookshelf/my-books` refreshes the signed My Books directory from the instance's local Nostr relay before rendering. If the external Mercury service cannot resolve the saved publication references, the page retries through this application's read-only Books API.

## Overview

The My Books list is a parameterized replaceable kind `30045` event with the stable `d` tag `my-book-collection`. A browser may publish a newer copy through another client or relay, so a database-only lookup can be stale. The page probes the configured local relay on each My Books load and projects a returned event through the normal generic projector. NIP-01 replaceable-event ordering keeps the newest revision and ignores stale relay copies.

The normal book resolver still uses Mercury first. On a Mercury transport or HTTP failure, it uses the endpoints below `/books/api` on this instance instead. Both paths use the bundle's same event-to-book mapping, preserving directory order and missing-item handling.
The public Books API deliberately returns bare event arrays and objects. `BooksApiMercuryHttpClient` adapts only the internal fallback response to Mercury's `data` envelope, leaving the public API contract unchanged.

If both HTTP sources fail, My Books queries the configured Books Elasticsearch alias directly through `BookshelfEsBookLoader`; the unavailable notice appears only when that final lookup is unavailable too.
## Flow

1. An authenticated reader opens `/bookshelf/my-books`.
2. `BookshelfDirectoryRefreshService` asks the configured local relay for `30045:<pubkey>:my-book-collection`.
3. A returned event is persisted through `GenericEventProjector`; its replaceable-event checks retain only the current revision.
4. `BookshelfDirectoryService` reads the resulting local directory and extracts book references.
5. `BookshelfBookLoader` resolves the references through Mercury, then retries through the local Books API only if Mercury fails.

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
