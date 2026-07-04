# Search Architecture

This folder contains documentation for the newsroom's search infrastructure, including the new formal `ContentSearchService` API and deprecation planning for forum pages.

## Quick Reference

| Document | Purpose |
|----------|---------|
| `content-search-api.md` | **Complete API reference** for `ContentSearchService` — all methods, parameters, examples, and error handling |
| `migration-to-content-search-api.md` | **Step-by-step migration guide** from `ArticleSearchInterface` to `ContentSearchService` with before/after examples |
| `forum-deprecation-plan.md` | **Roadmap for deprecating** forum pages and integrating topics into main feeds and article pages |

## Architecture Overview

```
Controllers / Components / Services
    ↓
ContentSearchService (high-level formal API)
    ↓
ArticleSearchInterface / UserSearchInterface (factory-selected)
    ↓
ElasticsearchArticleSearch / DatabaseArticleSearch
    ↓
Elasticsearch / PostgreSQL (backend)
```

## Key Documents

### `content-search-api.md`
Formal API specification with:
- Method signatures and behavior
- Parameter documentation
- Return value contracts
- Usage examples
- Error handling patterns
- Performance considerations
- Backend-specific notes

**Start here if you're:** using search in a controller or component.

### `migration-to-content-search-api.md`
Step-by-step migration instructions:
- 5 detailed "before/after" examples covering common patterns
- Pattern reference (pagination, availability checks, combining searches)
- Caching strategy recommendations
- Phased rollout plan
- Testing updates
- Troubleshooting tips

**Start here if you're:** refactoring existing code to use the new API.

### `forum-deprecation-plan.md`
Deprecation roadmap:
- Why deprecate the forum architecture
- Integration strategy for topics into feeds
- Phased timeline (parallel rendering → integration → removal)
- UI/UX integration points
- Related articles suggestions
- Admin UX for topic management

**Start here if you're:** planning the topic/feed integration overhaul.

---

## For New Developers

1. Read `content-search-api.md` to understand what operations are available
2. Find your use case in the "Integration Examples" section
3. Follow the API method signatures and examples
4. Refer to `migration-to-content-search-api.md` if migrating legacy code

## For Implementation

- **New search-related code**: Always use `ContentSearchService`
- **Existing code**: Plan migration per `migration-to-content-search-api.md`
- **Forum pages**: Will eventually redirect to feed-integrated pages per `forum-deprecation-plan.md`

## Service Injection

```php
// In controllers, components, commands:
use App\Service\Search\ContentSearchService;

public function __construct(
    private readonly ContentSearchService $contentSearch,
) {
}

// Use it:
$articles = $this->contentSearch->searchByTopics(['bitcoin'], limit: 20);
```

## Testing the API

```bash
# Verify service is registered
docker compose exec php bin/console debug:container ContentSearchService

# Test search availability
docker compose exec php bin/console cache:clear

# Monitor Elasticsearch (if enabled)
curl http://localhost:9200/_health

# Switch backends
# .env: ELASTICSEARCH_ENABLED=false
# docker compose restart php
```

## Related Files

- `src/Service/Search/ContentSearchService.php` — implementation (fully documented)
- `src/Service/Search/ArticleSearchInterface.php` — underlying interface
- `src/Service/Search/ArticleSearchFactory.php` — backend selection
- `documentation/Reader/advanced-search.md` — advanced search detail (legacy reference)
- `documentation/Elasticsearch/elasticsearch.md` — ES configuration and indexing

---

## Glossary

- **Article**: A published piece of content (kind 30023 longform event)
- **Topic**: A hashtag/tag that articles are labeled with (e.g., 'bitcoin')
- **Forum**: Legacy topic-browsing interface (`/forum`, `/forum/topic/...`) being deprecated
- **Feed**: Content discovery via home timeline, author articles, related articles
- **Taxonomy**: Hierarchical structure of categories → subcategories → tags
- **Backend**: Elasticsearch or PostgreSQL for search queries
- **Deduplication**: Removing duplicate articles by pubkey + slug

---

## FAQ

**Q: Should I use `ArticleSearchInterface` directly in new code?**  
A: No. Use `ContentSearchService`. The interface is for implementation details only.

**Q: What if I need a custom search that's not in the API?**  
A: Add a new method to `ContentSearchService` rather than using `ArticleSearchInterface` directly. This keeps search logic centralized.

**Q: Can I switch from Elasticsearch to database without changing my code?**  
A: Yes. Change `ELASTICSEARCH_ENABLED` and restart. All `ContentSearchService` calls work identically.

**Q: How do I integrate topics into the home feed?**  
A: See `forum-deprecation-plan.md` for the full integration strategy.

**Q: Are forum pages being removed soon?**  
A: No immediate removal, but planned for deprecation. See `forum-deprecation-plan.md` for timeline.

---

## Ownership

- **Architecture**: Search team / Platform team
- **API design**: Community input welcome
- **Migration coordination**: Frontend / Search teams

For questions, file an issue or refer to the relevant document.

