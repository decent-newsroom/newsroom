# ContentSearchService Quick Reference

```php
use App\Service\Search\ContentSearchService;

public function __construct(private readonly ContentSearchService $contentSearch) {}
```

## Common Calls

```php
$articles = $this->contentSearch->searchByTopics(['bitcoin', 'lightning'], limit: 20);
$related = $this->contentSearch->findRelatedArticles($article, limit: 6);
$counts = $this->contentSearch->getTopicsMetadata(['bitcoin', 'nostr']);
$latest = $this->contentSearch->getLatest(limit: 50, excludedPubkeys: $mutedPubkeys);
$results = $this->contentSearch->search('lightning network', limit: 12);
$byAuthor = $this->contentSearch->searchByAuthor($pubkeyHex, limit: 20);
```

## Guarantees

- Topic inputs are normalized.
- Article results are deduplicated by pubkey + slug.
- Backend errors are logged and return empty results.
- Elasticsearch and database backends share the same caller API.

## Testing

```php
$search = $this->createMock(ContentSearchService::class);
$search->method('findRelatedArticles')->willReturn([$article]);
```

See [Content Search API](content-search-api.md) for the full method list.