# Books API

The Books API is a public, read-only Elasticsearch API for Nostr publication events. It is independent of the Bookshelf bundle and reads only the configured external alias.

The implementation contract is [src/Api/Books/swagger.json](../../src/Api/Books/swagger.json). It lists only the five supported read-only endpoints.

## Configuration

Set `BOOKS_ELASTICSEARCH_INDEX=gutenberg-books` (or another existing alias). The API uses the existing `fos_elastica.client.default` connection. Elasticsearch or alias failures return `503`; there is no database or relay fallback.

## Routes

All routes begin with `/Books/api`.

| Method | Route | Response |
| --- | --- | --- |
| GET | `/events` | Bare array of events |
| POST | `/events/filter` | Bare array of events |
| GET | `/events/{eventId}` | Bare event object |
| POST | `/publications/search` | Bare array of kind 30040 events |
| POST | `/publications/sections/search` | Bare array of kind 30041 events |

Only those read endpoints are exposed. Publish, delete, and wiki routes are deliberately absent.

## Event filters

`GET /events` requires `since`, `until`, and `limit`. Repeat query keys for list fields, for example:

```text
/Books/api/events?since=0&until=2000000000&limit=10&kinds=30040&%23T=The%20Republic
```

`POST /events/filter` requires `limit` and accepts `ids`, `authors`, `kinds`, `since`, `until`, and case-sensitive tag filters formed as `#` plus one ASCII letter. Limits are 1-100 and `since` cannot exceed `until`.

## Publication searches

`POST /publications/search` accepts optional `q`, `title`, `author`, `language`, `subject`, `d`, `identifier`, and `limit` (default 25, maximum 100). Structured metadata uses these canonical mappings:

- `title` -> `tags_flat.T`
- `author` -> `tags_flat.N`
- `language` -> `tags_flat.l`
- `subject` -> `tags_flat.t`
- `d` -> `tags_flat.d`
- URL `identifier` -> `tags_flat.s`; other identifiers -> `tags_flat.i`

`POST /publications/sections/search` requires `q` with at least four characters. Quoted queries are phrase searches; unquoted multi-word queries require all meaningful content words. Space and hyphen variants are searched together.

## Responses and errors

Successful responses follow the bare Mercury `PubEvent` schema: `content`, `created_at`, `id`, `kind`, `pubkey`, `sig`, and `tags`. Invalid requests return `400` with `{ "error": "Invalid request", "details": [...] }`; missing IDs return `404`; Elasticsearch failures return `503`.
