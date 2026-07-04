# ContentSearchService — Quick Reference

## Inject the Service

```php
use App\Service\Search\ContentSearchService;

class MyController {
    public function __construct(
        private readonly ContentSearchService $contentSearch,
    ) {}
}
```

## Common Operations

### 1. Find Articles by Topic

```php
$articles = $this->contentSearch->searchByTopics(
    ['bitcoin', 'lightning'],
    limit: 20,
    offset: 0
);
```

### 2. Find Related Articles (Recommended!)

```php
// Simplest method for "related articles" section
$related = $this->contentSearch->findRelatedArticles($article, limit: 6);
```

### 3. Get Topic Counts

```php
$counts = $this->contentSearch->getTopicsMetadata(['bitcoin', 'nostr']);
// Returns: ['bitcoin' => 142, 'nostr' => 89]
```

### 4. Build Taxonomy with Counts

```php
$enriched = $this->contentSearch->buildTaxonomyWithCounts($myTaxonomy);
// Adds 'count' field to each subcategory
```

### 5. Search by Author

```php
$articles = $this->contentSearch->searchByAuthor($pubkeyHex, limit: 20);
```

### 6. Get Latest Articles

```php
$latest = $this->contentSearch->getLatest(limit: 50);

// Or exclude certain authors
$filtered = $this->contentSearch->getLatest(
    limit: 50,
    excludedPubkeys: ['pubkey1', 'pubkey2']
);
```

### 7. Free-Text Search

```php
$results = $this->contentSearch->search('lightning network', limit: 12);
```

### 8. Deduplicate Results (if combining multiple searches)

```php
$combined = array_merge(
    $this->contentSearch->searchByTopics(['bitcoin'], limit: 50),
    $this->contentSearch->searchByTopics(['ethereum'], limit: 50),
);
$unique = $this->contentSearch->deduplicateArticles($combined);
```

### 9. Check If Search Backend Available

```php
if ($this->contentSearch->isSearchAvailable()) {
    // Use search features
} else {
    // Show cached/fallback content
}
```

## Key Points

✅ **Tag normalization**: Automatic (lowercase, trim, deduplicate)  
✅ **Error handling**: Returns empty array on error (no exceptions)  
✅ **Deduplication**: Automatic (by pubkey + slug)  
✅ **Backend transparent**: Works with ES or database  

❌ **No try-catch needed**: Service handles all errors  
❌ **No manual pagination**: Use `limit` + `offset` parameters  
❌ **No manual dedup**: Service deduplicates by default  

## Caching Strategy

```php
// Cache topic metadata (changes slowly)
$counts = $this->cache->get('topics:metadata', function () {
    return $this->contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);
});

// Don't cache dynamic search results (search box, filters)
$results = $this->contentSearch->search($userQuery);
```

## Testing

```php
// Mock in unit tests
$mockSearch = $this->createMock(ContentSearchService::class);
$mockSearch->expects($this->once())
    ->method('findRelatedArticles')
    ->willReturn([/* articles */]);

// Use real service in integration tests
$contentSearch = self::getContainer()->get(ContentSearchService::class);
```

## Documentation References

| Need | Link |
|------|------|
| Full API | `documentation/Search/content-search-api.md` |
| Examples | `skills/use-content-search-api.md` |
| Refactoring | `documentation/Search/migration-to-content-search-api.md` |
| Strategy | `documentation/Search/forum-deprecation-plan.md` |
| Source | `src/Service/Search/ContentSearchService.php` |

## Common Errors & Fixes

| Error | Fix |
|-------|-----|
| Empty results when data exists | Check if Elasticsearch is enabled: `ELASTICSEARCH_ENABLED` in `.env` |
| "Service not found" | Service should auto-register via `App\:` in `services.yaml` |
| Slow queries | Use smaller `limit`, cache results (30s-5min), or check backend health |

## One-Liners

```php
// Show related articles
return $twig->render('template', ['related' => $this->contentSearch->findRelatedArticles($article, 6)]);

// Get topic stats
return $this->json($this->contentSearch->getTopicsMetadata(['bitcoin', 'ethereum']));

// Search by topic with pagination
$page = (int) $request->get('page', 1);
$articles = array_slice(
    $this->contentSearch->searchByTopics(['bitcoin'], limit: 200),
    ($page - 1) * 20,
    20
);
```

---

**For more info**: See `documentation/Search/INDEX.md` or search codebase for `ContentSearchService`


