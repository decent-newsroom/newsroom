# Migrating to ContentSearchService

## Overview

This guide explains how to migrate from the lower-level `ArticleSearchInterface` to the new `ContentSearchService` for all content search operations.

## Why Migrate?

The `ContentSearchService` provides:
- **Cleaner API**: Purpose-built methods instead of generic interface
- **Better naming**: `findRelatedArticles()` vs `findByTopics()`
- **Less boilerplate**: No need for manual deduplication, normalization
- **Formal semantics**: Clear, documented behavior for each operation
- **Future-proof**: Single point of change for search infrastructure

## Migration Checklist

- [ ] Replace `ArticleSearchInterface` injections with `ContentSearchService`
- [ ] Update method calls to use the new API
- [ ] Remove manual tag normalization (lowercasing, trimming)
- [ ] Remove manual deduplication logic
- [ ] Add relevant caching (if needed)
- [ ] Test with Elasticsearch enabled and disabled
- [ ] Update unit tests to mock `ContentSearchService`

## Step-by-Step Examples

### Example 1: Forum Topic Page

**Before:**
```php
use App\Service\Search\ArticleSearchInterface;

class ForumController extends AbstractController
{
    #[Route('/forum/topic/{key}', name: 'forum_topic')]
    public function topic(
        string $key,
        ArticleSearchInterface $articleSearch,
        Request $request
    ): Response {
        [$cat, $sub] = explode('-', $key, 2);
        $tags = ForumTopics::TOPICS[$cat]['subcategories'][$sub]['tags'];
        
        // Manual tag normalization
        $tags = array_map('strtolower', $tags);
        $tags = array_map('trim', $tags);
        
        $page = (int) $request->query->get('page', 1);
        $perPage = 20;
        
        // Fetch and deduplicate
        $articles = $articleSearch->findByTopics($tags, $perPage * 10, 0);
        $articles = $this->deduplicateArticles($articles);
        
        // Manual pagination
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);
        // ... render
    }
    
    private function deduplicateArticles(array $articles): array
    {
        $seen = [];
        $unique = [];
        foreach ($articles as $article) {
            $key = $article->getPubkey() . '|' . $article->getSlug();
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $article;
            }
        }
        return $unique;
    }
}
```

**After:**
```php
use App\Service\Search\ContentSearchService;

class ForumController extends AbstractController
{
    #[Route('/forum/topic/{key}', name: 'forum_topic')]
    public function topic(
        string $key,
        ContentSearchService $contentSearch,
        Request $request
    ): Response {
        [$cat, $sub] = explode('-', $key, 2);
        $topics = ForumTopics::TOPICS[$cat]['subcategories'][$sub]['tags'];
        
        // ContentSearchService handles normalization and deduplication
        $page = (int) $request->query->get('page', 1);
        $perPage = 20;
        $articles = $contentSearch->searchByTopics(
            $topics,
            limit: $perPage * 10
        );
        
        // Manual pagination still needed
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);
        // ... render (cleaner, less boilerplate)
    }
    
    // Remove deduplicateArticles() method — no longer needed
}
```

**Key improvements:**
- Removed manual tag normalization (handled by `searchByTopics()`)
- Removed manual deduplication (handled by `searchByTopics()`)
- Removed helper method
- Clearer intent with descriptive method name

---

### Example 2: Related Articles Component

**Before:**
```php
use App\Service\Search\ArticleSearchInterface;

class RelatedArticles extends AbstractLiveComponent
{
    public function __construct(
        private ArticleSearchInterface $articleSearch,
    ) {
    }

    public function mount(Article $article): void
    {
        $tags = $article->getTopics() ?? [];
        if (empty($tags)) {
            $this->articles = [];
            return;
        }

        try {
            $articles = $this->articleSearch->findByTopics($tags, 20, 0);
            // Manual deduplication
            $articles = $this->deduplicateArticles($articles);
            // Manual filtering of self
            $this->articles = array_filter(
                $articles,
                fn($a) => $a->getPubkey() !== $article->getPubkey() 
                       || $a->getSlug() !== $article->getSlug()
            );
        } catch (\Throwable $e) {
            $this->articles = [];
        }
    }

    private function deduplicateArticles(array $articles): array
    {
        // ... deduplication logic
    }
}
```

**After:**
```php
use App\Service\Search\ContentSearchService;

class RelatedArticles extends AbstractLiveComponent
{
    public function __construct(
        private ContentSearchService $contentSearch,
    ) {
    }

    public function mount(Article $article): void
    {
        // One clean method call, handles everything
        $this->articles = $this->contentSearch->findRelatedArticles($article, limit: 6);
    }

    // Remove deduplicateArticles() method
}
```

**Key improvements:**
- Single, purpose-built method: `findRelatedArticles()`
- No error handling needed (method returns empty array on error)
- No deduplication logic
- Much more readable

---

### Example 3: Topic Taxonomy Index

**Before:**
```php
use App\Service\Search\ArticleSearchInterface;

class ForumController extends AbstractController
{
    #[Route('/forum', name: 'forum')]
    public function index(
        ArticleSearchInterface $articleSearch,
        CacheInterface $cache,
    ): Response {
        $categoriesWithCounts = $cache->get(
            'forum.index.counts.v2',
            function (ItemInterface $item) use ($articleSearch) {
                $item->expiresAfter(30);
                
                // Manual tag flattening
                $allTags = [];
                foreach (ForumTopics::TOPICS as $cat) {
                    foreach ($cat['subcategories'] as $sub) {
                        foreach ($sub['tags'] as $tag) {
                            $allTags[strtolower($tag)] = true;
                        }
                    }
                }

                // Fetch counts
                $counts = [];
                if ($articleSearch->isAvailable()) {
                    try {
                        $counts = $articleSearch->getTagCounts(array_keys($allTags));
                    } catch (\Throwable $e) {
                        // Ignore
                    }
                }

                // Manual taxonomy rehydration
                return $this->hydrateCategoryCounts(ForumTopics::TOPICS, $counts);
            }
        );

        return $this->render('forum/index.html.twig', [
            'topics' => $categoriesWithCounts,
        ]);
    }

    private function hydrateCategoryCounts(array $taxonomy, array $counts): array
    {
        $out = [];
        foreach ($taxonomy as $catKey => $cat) {
            $subs = [];
            foreach ($cat['subcategories'] as $subKey => $sub) {
                $sum = 0;
                foreach ($sub['tags'] as $tag) {
                    $sum += $counts[strtolower($tag)] ?? 0;
                }
                $subs[$subKey] = $sub + ['count' => $sum];
            }
            $out[$catKey] = $cat;
            $out[$catKey]['subcategories'] = $subs;
        }
        return $out;
    }
}
```

**After:**
```php
use App\Service\Search\ContentSearchService;

class ForumController extends AbstractController
{
    #[Route('/forum', name: 'forum')]
    public function index(
        ContentSearchService $contentSearch,
        CacheInterface $cache,
    ): Response {
        $categoriesWithCounts = $cache->get(
            'forum.index.counts.v2',
            function (ItemInterface $item) use ($contentSearch) {
                $item->expiresAfter(30);
                
                // One method call replaces all boilerplate
                return $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);
            }
        );

        return $this->render('forum/index.html.twig', [
            'topics' => $categoriesWithCounts,
        ]);
    }

    // Remove hydrateCategoryCounts() method
}
```

**Key improvements:**
- Entire taxonomy enrichment in one line
- No manual tag flattening logic
- No error handling needed
- No helper method required
- Much shorter, clearer intent

---

### Example 4: Author Articles Listing

**Before:**
```php
use App\Service\Search\ArticleSearchInterface;

class AuthorController extends AbstractController
{
    public function articles(
        string $pubkey,
        ArticleSearchInterface $articleSearch,
        Request $request,
    ): Response {
        if (empty(trim($pubkey))) {
            throw $this->createNotFoundException('Invalid author');
        }

        $pubkey = strtolower(trim($pubkey));
        
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        
        try {
            $articles = $articleSearch->findByPubkey($pubkey, $perPage * 10, 0);
        } catch (\Throwable $e) {
            $articles = [];
        }
        
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);
        // ... render
    }
}
```

**After:**
```php
use App\Service\Search\ContentSearchService;

class AuthorController extends AbstractController
{
    public function articles(
        string $pubkey,
        ContentSearchService $contentSearch,
        Request $request,
    ): Response {
        if (empty(trim($pubkey))) {
            throw $this->createNotFoundException('Invalid author');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;
        
        // Normalization handled by the service
        $articles = $contentSearch->searchByAuthor(
            $pubkey,
            limit: $perPage * 10
        );
        
        $articlesPage = array_slice($articles, ($page - 1) * $perPage, $perPage);
        // ... render (cleaner, less defensive coding)
    }
}
```

**Key improvements:**
- No manual pubkey normalization
- No try-catch needed (empty array on error)
- Clearer method name: `searchByAuthor` vs `findByPubkey`
- More semantic

---

### Example 5: Unit Testing

**Before:**
```php
use App\Service\Search\ArticleSearchInterface;
use PHPUnit\Framework\TestCase;

class RelatedArticlesTest extends TestCase
{
    public function testFindsRelatedArticles(): void
    {
        $mockSearch = $this->createMock(ArticleSearchInterface::class);
        $mockSearch
            ->expects($this->once())
            ->method('findByTopics')
            ->with(['bitcoin', 'lightning'], 20, 0)
            ->willReturn([/* articles */]);

        $component = new RelatedArticles($mockSearch);
        $component->mount($article);
        
        // Assert...
    }
}
```

**After:**
```php
use App\Service\Search\ContentSearchService;
use PHPUnit\Framework\TestCase;

class RelatedArticlesTest extends TestCase
{
    public function testFindsRelatedArticles(): void
    {
        $mockSearch = $this->createMock(ContentSearchService::class);
        $mockSearch
            ->expects($this->once())
            ->method('findRelatedArticles')
            ->with($article, 6)
            ->willReturn([/* articles */]);

        $component = new RelatedArticles($mockSearch);
        $component->mount($article);
        
        // Assert...
    }
}
```

**Key improvements:**
- Mocking semantic methods vs low-level interface
- Tested behavior matches user intent
- Easier to understand test assertions

---

## Common Patterns

### Pattern 1: Search with Manual Pagination

If you're doing:
```php
$articles = $articleSearch->findByTopics($tags, 1000, 0);
$page = array_slice($articles, ($page - 1) * $perPage, $perPage);
```

Keep doing that! Some operations require fetching many results for client-side pagination. `ContentSearchService` doesn't change this pattern.

### Pattern 2: Checking Search Availability

If you check:
```php
if ($articleSearch->isAvailable()) {
    // ...
}
```

Use the new API:
```php
if ($contentSearch->isSearchAvailable()) {
    // ...
}
```

### Pattern 3: Combining Multiple Searches

If you combine results from multiple searches:
```php
$articles = array_merge(
    $articleSearch->findByTopics($tags1, 50, 0),
    $articleSearch->findByTopics($tags2, 50, 0),
);
$unique = $this->deduplicateArticles($articles);
```

Use the new API:
```php
$articles = array_merge(
    $contentSearch->searchByTopics($tags1, limit: 50),
    $contentSearch->searchByTopics($tags2, limit: 50),
);
$unique = $contentSearch->deduplicateArticles($articles);
```

---

## Caching Strategy

The `ContentSearchService` doesn't include built-in caching (delegated to callers). Cache frequently accessed data:

```php
// Cache topic metadata (changes slowly)
$this->cache->get('topics:metadata', function () use ($contentSearch) {
    return $contentSearch->buildTaxonomyWithCounts(ForumTopics::TOPICS);
}, CacheItem::Infinite);

// Cache author articles (changes when they publish)
$this->cache->get("author:$pubkey:articles", function () use ($contentSearch, $pubkey) {
    return $contentSearch->searchByAuthor($pubkey, limit: 100);
}, CacheItem::Hour);

// Don't cache dynamic searches (search box, filters)
$results = $contentSearch->search($query);
```

---

## Rollout Phases

### Phase 1: Parallel (Week 1-2)
- Create `ContentSearchService`
- Migrate non-critical controllers (author page, related articles)
- Keep forum controllers unchanged
- Monitor for issues

### Phase 2: Core Migration (Week 2-3)
- Migrate forum controllers
- Update Twig Live Components
- Update helper traits

### Phase 3: Removal (Week 3-4)
- Document deprecation of direct `ArticleSearchInterface` usage
- Schedule removal in next major version
- Update contribution guidelines

---

## Troubleshooting

### "Service not found" error
Ensure `ContentSearchService` is registered in `services.yaml`. Based on `App\:` auto-wiring, it should be automatic.

### Tests still failing
Make sure you're mocking `ContentSearchService`, not `ArticleSearchInterface`.

### Search returns different results
Both use the same backend, so results should be identical. If not:
- Check tag normalization (case sensitivity)
- Verify Elasticsearch index is up-to-date
- Compare raw backend queries

---

## Support

Questions? Refer to:
- `documentation/Search/content-search-api.md` — full API reference
- `src/Service/Search/ContentSearchService.php` — source code and inline docs
- Related: `documentation/Reader/advanced-search.md`


