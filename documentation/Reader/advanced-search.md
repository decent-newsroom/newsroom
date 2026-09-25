# Advanced Search

The search page (`/search`) now includes an advanced filters panel that lets users refine results beyond the basic text query.

## Filters

| Filter | Input | Description |
|--------|-------|-------------|
| **Date range** | Two date pickers (from / to) | Restricts results to articles published within the selected window. |
| **Author** | Text input (npub or hex pubkey) | Filters by a specific author. Accepts `npub1…` (auto-converted to hex) or raw 64-char hex pubkeys. |
| **Tags** | Comma-separated text | Requires **all** listed tags to be present on the article (AND logic). |
| **Sort** | Dropdown | Relevance (default — score + date), Newest first, Oldest first. |

> **Note:** Drafts (kind 30024) are not indexed and cannot be searched. The `IndexableArticleChecker` explicitly excludes them. Only published articles (kind 30023) appear in search results.

A "Looking for people?" hint links to the existing user search page (`/users/search`) with the current query pre-filled.

## UI

The filters live inside a collapsible panel toggled by the **"Advanced filters"** button that sits between the search input and the results. An orange dot badge (●) appears on the toggle button when any filter is active.

All filter inputs use `data-model="norender|..."` bindings to Twig Live Component `#[LiveProp]` values. Editing waits for **Apply**, which searches with the current text and filters. A blank text query with active filters is valid; a blank form without filters keeps the empty prompt. The available actions are:

- **Apply** — triggers the `search` LiveAction with the current filters.
- **Clear from date** and **Clear to date** — clear only that date and refresh results.
- **Clear filters** — resets all filter LiveProps to their defaults and refreshes results.

Invalid author keys, date ranges, and sort values show a translated error instead of silently dropping a visible filter. Search session state stores the criteria and re-queries on revisit; it does not reuse result objects keyed only by text.

## Dual implementation

The `advancedSearch(string $query, SearchFilters $filters, int $limit, int $offset)` method is defined on `ArticleSearchInterface` and implemented in both:

### Elasticsearch (`ElasticsearchArticleSearch`)
Builds an Elastica `BoolQuery`:
- Text query → `MultiMatch` + `MatchPhrase` boost (same as the basic `search()`).
- Date range → `Range` filter on `createdAt` (ES `date` type), from UTC midnight inclusive to the next-day UTC midnight exclusive.
- Author → `Term` filter on `pubkey`.
- Tags → one `Term` filter per tag on `topics` (AND).
- Kind → `Term` filter on `kind` (new ES integer field).
- Sort → `_score` + `createdAt` desc (relevance), or pure `createdAt` asc/desc.
- `collapse` on `slug` to deduplicate.

### Database (`DatabaseArticleSearch` → `ArticleRepository`)
Uses Doctrine QueryBuilder for most filters. Both database paths use the same UTC half-open calendar-day bounds as Elasticsearch. When tags are involved, search falls back to native PostgreSQL SQL with `topics::jsonb @>` containment operators.

## Elasticsearch mapping changes

The following changes were made to `config/packages/fos_elastica.yaml`:

```yaml
# Before
createdAt:
    type: keyword

# After
createdAt:
    type: date
    format: strict_date_optional_time||epoch_millis
kind:
    type: integer
```

If the production index still has the old mapping, rebuild it after deployment:
```bash
docker compose exec php bin/console fos:elastica:populate
```

## Files

| File | Role |
|------|------|
| `src/Dto/SearchFilters.php` | DTO holding all filter values |
| `src/Service/Search/ArticleSearchInterface.php` | Added `advancedSearch()` |
| `src/Service/Search/ElasticsearchArticleSearch.php` | ES implementation |
| `src/Service/Search/DatabaseArticleSearch.php` | DB implementation |
| `src/Repository/ArticleRepository.php` | `advancedSearch()` + `advancedSearchWithTags()` |
| `src/Twig/Components/SearchComponent.php` | Filter LiveProps, `buildFilters()`, `clearFilters()` |
| `templates/components/SearchComponent.html.twig` | Filter panel markup |
| `assets/styles/03-components/search.css` | Filter panel styles |
| `assets/controllers/search/advanced_filters_controller.js` | Stimulus controller stub |
| `translations/messages.en.yaml` | `search.filters.*` keys |
| `config/packages/fos_elastica.yaml` | `createdAt` → date, added `kind` |

## Filter correctness remediation (2026-09-25)

The code changes are implemented. Deployment still needs a check that the production Elasticsearch `createdAt` field is mapped as `date`; a stale index needs repopulation.

### Observed defects

- `SearchComponent::search()` returns before searching when the query is empty. This blocks filter-only searches even though both backends support them; PHP `empty()` also treats the literal query `0` as empty.
- The component reuses session results by query text alone. Changing date, author, tags, sort, or page with the same text can display stale results. `mount()` can restore results without restoring the filters that produced them.
- The date inputs have no individual clear controls. `clearFilters()` resets fields and the session cache but leaves previously displayed results until another search.
- Invalid author input is silently ignored. Valid uppercase hex input is preserved instead of normalized for an exact `pubkey` term lookup.
- Elasticsearch receives bare calendar dates for range bounds. Its [documented missing-time defaults](https://www.elastic.co/docs/reference/query-languages/query-dsl/query-dsl-range-query) can make `gte` start at the end of the selected `dateFrom` day. The database path uses different end-of-day handling.

### Implementation sequence

1. Normalize the submitted query with `trim()` and compare strictly with `''` in the component and both advanced backends. Keep an untouched, unfiltered blank form in its empty state. When any filter or non-default sort is active, call `advancedSearch()` even with no query text. Reset page to 1 when criteria change.
2. Replace the query-only session result cache with a normalized criteria snapshot (query, dates, author, tags, kind, sort, and page). Re-query for results rather than restoring serialized result objects. Restore the matching controls on navigation; make an explicit empty `?q=` override an older session query.
3. Add a separately labeled clear button for each date input using LiveActions that update its LiveProp and refresh results while preserving the other date and filters. Keep the existing Apply and Clear filters actions coherent with the displayed results. Add labels in every existing locale file.
4. Validate author input visibly as an npub or 64-character hex key, normalize accepted hex to lowercase, and validate date order and sort values before dispatch. Do not silently drop a visible filter.
5. Use explicit day bounds in a defined timezone for both backends: inclusive start of `dateFrom`, exclusive start of the day after `dateTo`. Verify the production Elasticsearch `createdAt` mapping is `date`; reindex only if that mapping is stale.
6. Add component tests for same-query filter/sort changes, blank-query filters, literal `0`, clearing one date, clearing all filters, page reset, and session restoration. Add Elasticsearch query assertions and database integration cases for oldest/newest and date boundaries. Smoke-test the complete form against an Elasticsearch-enabled Docker environment.
7. Update this feature document with final behavior and add one bugfix entry to the top in-development Changelog version when the fix lands.

### Acceptance criteria

- Applying oldest/newest to the same text query changes result order; each selected filter narrows results and removing it updates them.
- Date, tag, and author filters return matching articles with an empty query; a blank form with no active criteria remains an empty prompt.
- Either date can be cleared independently, including after using the picker, and the results match the controls shown.
- The selected date range includes both calendar days in Elasticsearch and PostgreSQL, including articles at the start and end boundaries.
- Reloading or revisiting search cannot show results produced by filters that are no longer visible.

### TypeSafe AI decision record

- **Question and options:** For a submitted search with no text and no active filters, choose `show_empty_prompt`, `show_recent_articles`, or `show_all_relevance`.
- **Evidence:** The current untouched search page displays no results. Both search backends support filter-only search, but the Live Component blocks it. Results are paginated. No production result counts were provided.
- **Shared state:** `{"product":"Open-source Nostr newsroom article search page.","user_request":"Selected advanced filters should affect search. Oldest first currently appears unchanged. Users cannot clear an individual selected date. Submitting active filters without query text currently finds nothing.","observed_behavior":"A blank untouched search page currently shows no results. SearchComponent exits before backend search on blank query and caches results only by query text. Elasticsearch advanced search supports match_all plus filters and date sorting. Results are paginated."}`
- **Jev result:** On 2026-09-25, `jev-1.13.0` selected `show_empty_prompt` (probability 0.86; `show_recent_articles` 0.10; `show_all_relevance` 0.04; confidence 0.79; usage 519 input and 52 output tokens).
- **Human decision:** Preserve the empty prompt only when there is no text and no active filter or sort. Filter-only searches must run. Search rules, state, and validation remain deterministic code; no runtime TypeSafe dependency is needed.
- **Validation:** Exercise the blank-unfiltered state and the filter-only cases in component tests and an Elasticsearch-enabled smoke test.

### Verification performed

- Component, Elasticsearch query, and transactional PostgreSQL date-boundary tests: 14 tests, 52 assertions passed in Docker with the repository XML deprecation gate disabled.
- PHP lint passed for the modified search component and backends; PHPStan and Symfony container lint passed for the component; Twig and translation YAML lint passed, AssetMapper compiled, and the local /search page returned HTTP 200.
- The PostgreSQL test uses a temporary timestamp(6) column for its fractional-second boundary; the current article table stores whole seconds. Production mapping and live Elasticsearch result checks remain deployment checks because production access is not available in this workspace. The full local PHPUnit suite reached 835 tests with 1 error and 5 failures in HomePage, NostrAuthenticator, and UnfoldAdminExtension tests; the focused search tests passed.
