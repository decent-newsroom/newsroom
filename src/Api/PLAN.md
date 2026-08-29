# Books API implementation plan

## Objective

Implement a public, read-only Books API backed exclusively by the existing
Elasticsearch connection and the `gutenberg-books` index alias.

The HTTP interface must follow the Mercury contract described in
`Mercury/swagger.json`, except for the explicitly excluded write and wiki
operations. This implementation is independent of `BookshelfBundle` and its
`MercuryApiClient`.

In particular, successful responses follow the Swagger schemas directly:

- collection endpoints return a bare JSON array of Nostr events;
- the event lookup endpoint returns one bare Nostr event object;
- responses are not wrapped in a top-level `data` property.

## Scope

Implement these endpoints under the `/Books` prefix:

| Method | Route | Purpose |
|---|---|---|
| `GET` | `/Books/api/events` | Query events using URL-based NIP-01 filters |
| `POST` | `/Books/api/events/filter` | Query events using a JSON NIP-01 filter |
| `GET` | `/Books/api/events/{event_id}` | Retrieve an event by its exact event ID |
| `POST` | `/Books/api/publications/search` | Search kind `30040` publication indexes |
| `POST` | `/Books/api/publications/sections/search` | Search kind `30041` publication sections |

Do not implement:

- `POST /api/events`;
- `DELETE /api/events/{event_id}`;
- `/api/wiki/search`;
- event publishing, deletion, relay access, or database fallback;
- any dependency on the Bookshelf bundle.

## Source data and configuration

The API reads the existing Elasticsearch index described in
`Elasticsearch/index.json`.

Add a dedicated index-name setting so the API does not assume that the
application's article index is the book index:

```dotenv
BOOKS_ELASTICSEARCH_INDEX=gutenberg-books
```

Reuse the configured `fos_elastica.client.default` connection. Access the
external index through its alias and do not register it as a Doctrine-backed
FOS Elastica index. The application must never create, populate, update, or
delete this index.

The Books API has no database fallback. An unavailable Elasticsearch service
or missing index alias must produce an HTTP `503` response.

## Proposed module structure

Keep the implementation isolated from the application's article-search layer:

```text
src/Api/Books/
  Controller/
    EventController.php
    PublicationController.php
  Dto/
    Nip01Filter.php
    PublicationSearchRequest.php
    SectionSearchRequest.php
  Elasticsearch/
    BooksIndex.php
    EventQueryBuilder.php
    PublicationQueryBuilder.php
    SectionQueryBuilder.php
  Http/
    ApiError.php
    RequestDecoder.php
  Presenter/
    NostrEventPresenter.php
```

Add `config/routes/books_api.yaml` to import attribute routes from
`src/Api/Books/Controller/` with the `/Books` prefix. Route names should use a
dedicated `books_api_` prefix.

## Event representation

Every successful result must contain only the fields from the Swagger
`PubEvent` definition:

```json
{
  "content": "",
  "created_at": 0,
  "id": "",
  "kind": 30040,
  "pubkey": "",
  "sig": "",
  "tags": []
}
```

`NostrEventPresenter` should:

1. Read these fields from the Elasticsearch document `_source`.
2. Normalize scalar types required by the contract.
3. Require `tags` to be an array of arrays.
4. Exclude Elasticsearch-only fields such as `_index`, `_score`, and
   `tags_flat`.
5. Skip malformed documents and log their Elasticsearch document ID.

Do not synthesize tags from `tags_flat` and do not alter the original tag
names or values. Modifying a signed event would make its payload inconsistent
with its event ID and signature.

## Request parsing and validation

Centralize decoding and validation rather than duplicating it in controllers.
Validation errors return HTTP `400` with a stable JSON error object:

```json
{
  "error": "Invalid request",
  "details": ["limit must be between 1 and 100"]
}
```

Validation requirements:

- reject malformed JSON and non-object request bodies;
- reject unknown top-level request properties;
- validate event IDs and pubkeys as lowercase or uppercase hexadecimal strings;
- validate kinds and timestamps as integers;
- enforce `since <= until` when both are present;
- require a positive result limit and cap it at `100` as specified by Swagger;
- accept only tag filters whose key is exactly `#` plus one ASCII letter;
- preserve tag-name case, so `#T` and `#t` address different fields;
- reject multi-letter filters such as `#title` and `#author`.

## Elasticsearch query implementation

### Shared rules

All search operations must:

- apply the caller's limit through Elasticsearch `size`;
- select only the Nostr event fields from `_source`;
- avoid scripts and unbounded result windows;
- use deterministic secondary ordering by `id` when scores or timestamps are
  equal;
- convert Elasticsearch exceptions to an API `503` without exposing hostnames,
  credentials, or raw exception text to callers;
- log the operation, duration, result count, and sanitized failure details.

### NIP-01 event filters

Map filters as follows:

| API filter | Elasticsearch field/query |
|---|---|
| `ids` | `id` keyword terms |
| `authors` | `pubkey` keyword terms |
| `kinds` | `kind` terms |
| `since` | `created_at >= since` |
| `until` | `created_at <= until` |
| `#x` | `tags_flat.x` terms |
| `limit` | query size |

Sort matching events by `created_at` descending and then `id` ascending.

For `GET /Books/api/events`, `since`, `until`, and `limit` are required, as
specified by the Swagger operation. Support the same optional `ids`,
`authors`, `kinds`, and single-letter tag filters as the POST filter using
repeated query parameters. Define and test one canonical query-string format;
do not silently interpret ambiguous comma-containing tag values.

For `POST /Books/api/events/filter`, `limit` is required. An empty filter other
than `limit` is valid and returns the newest events up to the requested limit.

### Event lookup

`GET /Books/api/events/{event_id}` must:

1. Validate a complete 64-character hexadecimal ID.
2. Execute an exact `term` lookup against `id`.
3. Return the bare event object with HTTP `200`.
4. Return HTTP `404` with a JSON error when no event exists.

Do not use Elasticsearch's internal `_id` unless verification proves that it
is guaranteed to equal the Nostr event ID.

### Publication search

`POST /Books/api/publications/search` always adds `kind = 30040`.

Map structured fields to the supplied index:

| Request field | Elasticsearch field |
|---|---|
| `title` | `tags_flat.T` |
| `author` | `tags_flat.N` |
| `language` | `tags_flat.l` |
| `subject` | `tags_flat.t` |
| `d` | `tags_flat.d` |
| URL `identifier` | `tags_flat.s` |
| Other `identifier` | `tags_flat.i` |

The uppercase `N` and `T` fields are the canonical author and title mappings
for this Books API. Do not map these inputs through Bookshelf-specific
lowercase `author` or `title` tags.

Implement `q` as a metadata query over `N`, `T`, `d`, `i`, `s`, `t`, and the
available compatibility metadata fields. Rank exact keyword matches above
prefix matches and prefix matches above substring matches. Escape wildcard
metacharacters supplied by callers.

Because the supplied mapping stores metadata as keywords, substring matching
will require bounded case-insensitive wildcard queries. Enforce maximum input
lengths and the result limit to prevent expensive public queries.

Sort by `_score` descending, `created_at` descending, and `id` ascending. Do
not deduplicate replaceable-event revisions in the controller unless Mercury
contract tests demonstrate that the source API does so.

### Publication-section search

`POST /Books/api/publications/sections/search` always adds `kind = 30041`.

Search across:

- the analyzed `content` field;
- `tags_flat.d`;
- `tags_flat.T`;
- `tags_flat.title` when present in the external index.

Follow the Swagger behavior:

- require `q` and at least four characters;
- quoted queries require an exact contiguous phrase in content or metadata;
- unquoted multi-word queries require all meaningful content words;
- metadata remains eligible for matching the complete query;
- normalize query variants so spaces and hyphens are treated equivalently;
- strongly boost exact phrase matches;
- sort by `_score` descending, `created_at` descending, and `id` ascending.

## HTTP behavior

Responses use `Content-Type: application/json`.

Expected statuses:

| Status | Meaning |
|---|---|
| `200` | Successful event or event-list response |
| `400` | Invalid filter, search request, ID, or JSON body |
| `404` | Event not found |
| `405` | Unsupported method, including write/delete attempts |
| `503` | Elasticsearch unavailable or book index missing |

If the API is intended for direct browser use, add a route-scoped CORS
subscriber for `/Books/api/` and explicit `OPTIONS` handling. Do not apply a
new wildcard CORS policy to unrelated application routes.

## Implementation sequence

1. **Lock the contract**
   - Copy the supported endpoint list and schemas into tests.
   - Record bare-object and bare-array response expectations.
   - Add rejection tests proving publish, delete, and wiki routes do not exist.

2. **Add configuration and route loading**
   - Add `BOOKS_ELASTICSEARCH_INDEX` to `.env.dist` and the production template.
   - Bind the index alias to `BooksIndex` in `config/services.yaml`.
   - Add `config/routes/books_api.yaml` with the `/Books` prefix.

3. **Build the read-only Elasticsearch boundary**
   - Resolve the configured alias from `fos_elastica.client.default`.
   - Implement source filtering and centralized exception translation.
   - Add a lightweight availability check used by smoke tests, not on every
     request.

4. **Implement DTOs and validation**
   - Decode query strings and JSON bodies into typed request objects.
   - Enforce allowed fields, ranges, tag-filter shape, and search lengths.
   - Unit-test every accepted and rejected boundary value.

5. **Implement NIP-01 querying**
   - Build common event-filter clauses once for GET and POST.
   - Add exact event lookup.
   - Implement stable sorting and event presentation.

6. **Implement publication search**
   - Add structured metadata filters.
   - Add ranked free-text metadata queries.
   - Verify `N`/`T` author/title behavior against representative index
     documents.

7. **Implement section search**
   - Add quoted phrase and unquoted all-word behavior.
   - Add space/hyphen normalization and metadata fallback matching.
   - Verify relevance ordering with fixed fixtures.

8. **Add public API hardening**
   - Add scoped CORS if required.
   - Add request-length, query-length, and result-limit enforcement.
   - Ensure logs and errors never expose Elasticsearch credentials.

9. **Document and release**
   - Add one feature document at `documentation/API/books-api.md`.
   - Add one feature entry to the topmost version in `Changelog.md`.
   - Document the route prefix, environment variable, supported filters,
     response schemas, and error statuses.

## Test plan

### Unit tests

- NIP-01 DTO and query builder for every filter type.
- Single-letter, case-sensitive tag filters.
- Rejection of multi-letter and malformed tag filters.
- Publication field-to-`tags_flat` mappings, especially `N` and `T`.
- URL versus non-URL identifier mapping.
- Wildcard escaping and query-length limits.
- Quoted and unquoted section queries.
- Event presentation and malformed-source rejection.

### Controller tests

- Bare event-list response for each collection endpoint.
- Bare event response and `404` behavior for event lookup.
- Invalid JSON and unknown fields.
- Required GET parameters and limit boundaries.
- `503` translation for Elasticsearch failures.
- `405` or missing-route behavior for excluded operations.
- CORS and `OPTIONS`, if enabled.

### Elasticsearch integration tests

Use a disposable test index with the supplied mapping and representative
kind `30040` and `30041` documents. Cover:

- exact event lookup;
- timestamp and tag filtering;
- uppercase author/title metadata;
- publication ranking;
- quoted phrase ranking;
- hyphen/space equivalence;
- deterministic result ordering.

Never write fixtures to the production `gutenberg-books` alias.

## Verification commands

Run all commands inside the PHP container, in accordance with the repository
workflow:

```bash
docker compose exec php bin/console lint:container
docker compose exec php bin/console debug:router books_api
docker compose exec php bin/phpunit tests/Unit/Api/Books tests/Controller/Api/Books
docker compose exec php vendor/bin/phpstan analyse src/Api/Books tests/Unit/Api/Books tests/Controller/Api/Books
```

Perform final read-only smoke requests against each endpoint with the
configured Elasticsearch alias. Verify response bodies against
`Mercury/swagger.json`, not against the Bookshelf bundle client.

## Completion criteria

The work is complete when:

- all five read-only routes are available under `/Books/api/`;
- response bodies conform to the bare Swagger schemas;
- Elasticsearch is the only source of event data;
- author and title requests query `tags_flat.N` and `tags_flat.T`;
- no write, delete, or wiki endpoint is exposed;
- validation, controller, and Elasticsearch query tests pass;
- container linting and PHPStan pass;
- the API documentation and changelog entry are present.
