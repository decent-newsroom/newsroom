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

All filter inputs use `data-model` bindings to Twig Live Component `#[LiveProp]` values, so state is preserved across re-renders. Two actions are available:

- **Apply** — triggers the `search` LiveAction with the current filters.
- **Clear filters** — resets all filter LiveProps to their defaults.

## Dual implementation

The `advancedSearch(string $query, SearchFilters $filters, int $limit, int $offset)` method is defined on `ArticleSearchInterface` and implemented in both:

### Elasticsearch (`ElasticsearchArticleSearch`)
Builds an Elastica `BoolQuery`:
- Text query → `MultiMatch` + `MatchPhrase` boost (same as the basic `search()`).
- Date range → `Range` filter on `createdAt` (now mapped as ES `date` type).
- Author → `Term` filter on `pubkey`.
- Tags → one `Term` filter per tag on `topics` (AND).
- Kind → `Term` filter on `kind` (new ES integer field).
- Sort → `_score` + `createdAt` desc (relevance), or pure `createdAt` asc/desc.
- `collapse` on `slug` to deduplicate.

### Database (`DatabaseArticleSearch` → `ArticleRepository`)
Uses Doctrine QueryBuilder for most filters. When tags are involved, falls back to native PostgreSQL SQL with `topics::jsonb @>` containment operators for efficient JSON array matching.

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

After deploying, run:
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

