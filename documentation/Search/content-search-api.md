# Content Search API

`ContentSearchService` is the preferred host-level API for article discovery. It wraps `ArticleSearchInterface`, so callers do not need to care whether the backend is PostgreSQL or Elasticsearch.

## Use It For

- Topic/tag feeds
- Related articles
- Full-text search
- Author article search
- Latest article lists
- Topic count/taxonomy enrichment

New controller/component code should inject `ContentSearchService`. Use `ArticleSearchInterface` only when implementing a backend or intentionally bypassing the higher-level semantics.

## Methods

| Method | Purpose |
|---|---|
| `searchByTopics(array $topics, int $limit = 20, int $offset = 0): array` | Articles matching any normalized topic/tag. |
| `findRelatedArticles(Article $article, int $limit = 6): array` | Related articles by shared tags, excluding the source article. |
| `getTopicsMetadata(array $topics): array` | Topic counts keyed by normalized topic. |
| `search(string $query, int $limit = 12, int $offset = 0): array` | Free-text article search. |
| `searchByAuthor(string $pubkeyHex, int $limit = 20, int $offset = 0): array` | Articles by author pubkey. |
| `getLatest(int $limit = 50, array $excludedPubkeys = []): array` | Latest articles with optional author exclusions. |
| `findBySlugs(array $slugs, int $limit = 200): array` | Batch lookup by slug. |
| `isSearchAvailable(): bool` | Backend health/availability check. |
| `buildTaxonomyWithCounts(array $taxonomy): array` | Adds article counts to taxonomy entries. |
| `deduplicateArticles(array $articles): array` | Deduplicates by pubkey + slug. |

All public search methods normalize inputs where relevant and degrade to empty results on backend errors while logging the failure.

## Example

```php
use App\Service\Search\ContentSearchService;

final class TopicController
{
    public function __construct(private readonly ContentSearchService $search) {}

    public function __invoke(string $tag): array
    {
        return $this->search->searchByTopics([$tag], limit: 20);
    }
}
```

## Backend Selection

`ArticleSearchFactory` chooses the backend from `ELASTICSEARCH_ENABLED`:

- `true` -> `ElasticsearchArticleSearch`
- `false` -> `DatabaseArticleSearch`

Callers should not branch on the backend. Put backend-specific behavior behind the implementation classes.

## Testing

Unit tests normally mock `ContentSearchService` at the caller boundary. Service-level tests for search behavior live under `tests/Unit/Service/Search/`.