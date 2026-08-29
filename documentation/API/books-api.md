# Books API

The Books API is a public, read-only Elasticsearch API for Nostr publication events. It is independent of the Bookshelf bundle and reads only the configured external alias; it never creates, indexes, updates, or deletes book documents.

## Configuration

Set `BOOKS_ELASTICSEARCH_INDEX=gutenberg-books` (or another existing alias) in the environment. The API uses the existing `fos_elastica.client.default` connection. Elasticsearch or alias failures return `503`; there is no database or relay fallback.

## Routes

All routes begin with `/Books/api`.

| Method | Route | Response |
| --- | --- | --- |
| GET | `/events` | Bare array of events |
| POST | `/events/filter` | Bare array of events |
| GET | `/events/{event_id}` | Bare event object |
| POST | `/publications/search` | Bare array of kind 30040 events |
| POST | `/publications/sections/search` | Bare array of kind 30041 events |

Only those read endpoints are exposed. Publish, delete, and wiki routes are deliberately absent.

## Event filters

`GET /events` requires `since`, `until`, and `limit`. Use repeated query keys for list fields, for example:

```text
/Books/api/events?since=0&until=2000000000&limit=10&kinds=30040&%23T=The%20Republic
```

`POST /events/filter` accepts a JSON NIP-01 filter. It requires `limit` and accepts `ids`, `authors`, `kinds`, `since`, `until`, plus tag filters exactly shaped `#` plus one ASCII letter. Tag names remain case-sensitive: `#T` and `#t` query different fields. Limits are 2??y??y?100 and `since` cannot exceed `until`.

## Publication searches

`POST /publications/search` accepts optional `q`, `title`, `author`, `language`, `subject`, `d`, `identifier`, and `limit` (default 25, maximum 100). Structured metadata uses the canonical tags:

- `title` ?w^~)?v `tags_flat.T`
- `author`"??y??y? `tags_flat.N`
- `language`"??y??y? `tags_flat.l`
- `subject` ?w^~)?v `tags_flat.t`
- `d`+?u????R `tags_flat.d`
- URL `identifier`+?u????R `tags_flat.s`; other identifiers"??y??y? `tags_flat.i`

`POST /publications/sections/search` requires `q` with at least four characters. Quoted queries are phrase searches; unquoted multi-word queries require all meaningful content words. Space and hyphen variants are searched together.

## Event schema and errors

Successful results use the Mercury `PubEvent` schema with only `content`, `created_at`, `id`, `kind`, `pubkey`, `sig`, and `tags`. Malformed Elasticsearch documents are logged and omitted; no Elasticsearch metadata or generated tags are returned.

Invalid requests return `400` with `{ "error": "Invalid request", "details": [...] }`. A missing valid event ID returns `404`; Elasticsearch failures return `503` without internal connection details.
