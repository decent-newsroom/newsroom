# Search

## Overview

Article and user search with dual implementation (Elasticsearch or Database), anonymous user support, and NIP-05 aware user lookup.

## Architecture

| Component | File |
|-----------|------|
| Article search interface | `src/Service/Search/ArticleSearchInterface.php` |
| Database implementation | `src/Service/Search/DatabaseArticleSearch.php` |
| Elasticsearch implementation | `src/Service/Search/ElasticsearchArticleSearch.php` |
| Factory | `src/Service/Search/ArticleSearchFactory.php` |
| User search interface | `src/Service/Search/UserSearchInterface.php` |
| NIP-05 aware search | `src/Service/Search/Nip05AwareUserSearch.php` |
| Search controller | `src/Controller/Search/SearchController.php` |
| User search controller | `src/Controller/Search/UserSearchController.php` |
| Twig component | `src/Twig/Components/SearchComponent.php` |

### Implementation Selection

Controlled by `ELASTICSEARCH_ENABLED` env var. The factory creates the appropriate implementation at runtime.

### Anonymous vs Authenticated

| Feature | Anonymous | Authenticated |
|---------|-----------|---------------|
| Search articles | ✅ (5 results max) | ✅ (12 results max) |
| Session cache | ✅ | ✅ |
| Credit tracking | ❌ | ✅ |
| Save to reading lists | ❌ | ✅ |

### NIP-05 User Search

When a NIP-05 identifier (e.g. `bob@example.com`) is entered, the well-known endpoint is queried to resolve the hex pubkey, and the matching user is returned at the top of results.

### API Endpoints

- `GET /api/articles/search?q=...` — article search (used by embeds tab in editor)
- `GET /api/users/search?q=...` — user search (used by mentions tab in editor)

## Lessons Learned

- **URL parameter encoding**: Search queries with special characters must be properly encoded in Turbo Frame URLs to avoid broken pagination.
- **Stale cache**: Session-cached search results can become stale if articles are updated between searches. Cache is keyed by query + page number.

