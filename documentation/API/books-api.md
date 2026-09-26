# Books API

The Books API is a public, read-only Elasticsearch API for Nostr publication events. It is independent of the Bookshelf bundle and reads only the configured external alias.

The implementation contract is [src/Api/Books/swagger.json](../../src/Api/Books/swagger.json). It lists the six supported read-only endpoints.

## Configuration

Set `BOOKS_ELASTICSEARCH_INDEX=gutenberg-books` (or another existing alias). The API uses the existing `fos_elastica.client.default` connection. Elasticsearch or alias failures return `503`; there is no database or relay fallback.

## Routes

All routes begin with `/books/api`.

| Method | Route | Response |
| --- | --- | --- |
| GET | `/events` | Bare array of events |
| POST | `/events/filter` | Bare array of events |
| GET | `/events/{eventId}` | Bare event object |
| POST | `/publications/search` | Bare array of kind 30040 events |
| POST | `/publications/recommendations` | Bare array of recommended kind 30040 events |
| POST | `/publications/sections/search` | Bare array of kind 30041 events |

Only those read endpoints are exposed. Publish, delete, and wiki routes are deliberately absent.

## Event filters

`GET /events` requires `since`, `until`, and `limit`. Repeat query keys for list fields, for example:

```text
/books/api/events?since=0&until=2000000000&limit=10&kinds=30040&%23T=The%20Republic
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

## Publication recommendations

`POST /publications/recommendations` finds publications with subjects or authors matching a seed publication:

```json
{
  "seed_event_id": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "exclude_ids": [],
  "limit": 10
}
```

Replace the example seed with an indexed kind `30040` event ID. `seed_event_id` is required and must contain exactly 64 hexadecimal characters. `exclude_ids` is optional, accepts full event IDs, and is limited to 100 supplied IDs before deduplication. IDs are normalized to lowercase and duplicate exclusions are accepted and deduplicated. `limit` defaults to 10 and must be an integer from 1 to 50. Unknown properties are rejected.

### Ranking and data flow

1. Look up the seed by its Nostr `id` field, independently of Elasticsearch's `_id`. A missing seed returns `404`; a seed of another kind returns `400`.
2. Read subjects from raw `t` tags and authors from raw `N` tags in `_source`. Ignore blank values and placeholder authors `anonymous` and `unknown` (case-insensitively). Deduplicate the remaining values while preserving spelling and case, retaining at most 50 subjects and 10 authors.
3. Build an Elasticsearch `more_like_this` query on `tags_flat.t` using an artificial document containing the seed subjects. Set `min_term_freq: 1`, `min_doc_freq: 1`, `max_query_terms: 25`, `minimum_should_match: 1`, and boost `3`. Add an exact author match on `tags_flat.N` with a constant boost of `1`.
4. Require at least one subject or author match and restrict candidates to kind `30040`. Exclude the seed and caller-provided IDs. Sort by `_score` descending, then `id` ascending. With no subjects, author matching alone is used; with neither signal, return `[]`.
5. Retrieve up to three times the requested limit, capped at 150 candidates. Skip malformed events, exclude the seed's `(kind, pubkey, d)` coordinate, and keep the highest-ranked result for each remaining coordinate when a `d` tag is present. Return at most `limit` events, without changing their signed fields.

The response is a bare `PubEvent` array. It may contain fewer results than requested, including an empty array, when metadata or distinct eligible candidates are insufficient. This bounded candidate pool is not backfilled with additional queries.

### Index requirements and limitations

Recommendations use the existing external alias and Elasticsearch connection. No new configuration, mapping changes, index writes, or reindexing are required for the Gutenberg mapping with `tags_flat.t` and `tags_flat.N` as `keyword` fields and `_source` available. Raw `tags` may have indexing disabled because seed metadata is read from `_source`.

Subject and author matching is exact and case-sensitive: `Science fiction` and `Science fiction -- History` are different keywords. Placeholder author labels do not count as an author signal; other inconsistent or overly broad source metadata can still reduce recommendation quality. This release does not use title or content similarity, chapter text, semantic embeddings, reader history, or personalization. Language filtering is not supported because the supplied Gutenberg mapping lacks `tags_flat.l`. Different editions are not merged by title; only repeated publication coordinates are deduplicated. Elasticsearch failures return `503`, with no database, relay, or discovery fallback.

### Key files

| File | Role |
| --- | --- |
| `src/Api/Books/Dto/PublicationRecommendationRequest.php` | Request validation and limits |
| `src/Api/Books/Elasticsearch/RecommendationQueryBuilder.php` | Native Elasticsearch similarity query and filters |
| `src/Api/Books/Service/PublicationRecommendationService.php` | Seed lookup, metadata extraction, and result deduplication |
| `src/Api/Books/Controller/PublicationController.php` | HTTP endpoint and existing API response handling |

Seed lookup is logged as `publications.recommendations.seed` and candidate retrieval as `publications.recommendations`; request values and event IDs are not logged.

### Verification

Run the focused Books API tests inside Docker:

```bash
docker compose exec php bin/phpunit tests/Unit/Api/Books
```

The opt-in integration test at `tests/Service/Api/Books/PublicationRecommendationElasticsearchTest.php` verifies native MLT ranking against the Books keyword mapping, exact subjects, exclusions, coordinate deduplication, author-only recommendations, empty metadata, and Elasticsearch document IDs that differ from Nostr event IDs. Set `BOOKS_TEST_ELASTICSEARCH_URL` to a disposable Elasticsearch cluster reachable from the PHP container:

```bash
docker compose exec -e BOOKS_TEST_ELASTICSEARCH_URL=http://test-elasticsearch:9200 php bin/phpunit tests/Service/Api/Books/PublicationRecommendationElasticsearchTest.php
```

Replace the example URL with your test cluster. The test creates and deletes only a randomly named `books-recommendation-test-*` index; it does not use the production Books alias. Without this variable, the normal suite skips this integration test.

## Responses and errors

Successful responses follow the bare Mercury `PubEvent` schema: `content`, `created_at`, `id`, `kind`, `pubkey`, `sig`, and `tags`. Invalid requests return `400` with `{ "error": "Invalid request", "details": [...] }`; missing IDs return `404`; Elasticsearch failures return `503`.
