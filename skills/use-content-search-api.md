# Skill: Use ContentSearchService for Site-Wide Content Discovery

**Time estimate**: 20 minutes to learn, 5-10 minutes per implementation  
**Complexity**: Intermediate  
**Related skills**: N/A (foundational)

## What You'll Learn

How to use the new `ContentSearchService` for all content search and discovery operations across the newsroom. This service wraps Elasticsearch/database backends and provides a formal, semantic API for:

- Searching articles by topics
- Finding related articles  
- Getting topic analytics
- General text search
- Building topic taxonomies

## Prerequisites

- Familiar with dependency injection in Symfony
- Understand Twig components or controllers
- Read `documentation/Search/content-search-api.md` (reference only)

## When to Use This Skill

✅ **Use this skill when**:
- Building search features (new)
- Finding articles by topic
- Showing related articles on article pages
- Displaying topic indexes/menus
- Refactoring old `ArticleSearchInterface` code

❌ **Don't use this skill for**:
- User search (that's `UserSearchInterface`)
- Full-text search internals (that's Elasticsearch-specific)
- Custom low-level query logic (consult with search team)

## The Five-Minute Introduction

### What is ContentSearchService?

A high-level API that abstracts away whether Elasticsearch or PostgreSQL is your search backend. All method calls work identically — you can switch backends by changing one environment variable.

```php
use App\Service\Search\ContentSearchService;

// In a controller or component:
public function __construct(
    private readonly ContentSearchService $contentSearch,
) {}

// Search by topics
$articles = $this->contentSearch->searchByTopics(['bitcoin', 'lightning'], limit: 20);

// Find articles related to a given article
$related = $this->contentSearch->findRelatedArticles($currentArticle, limit: 6);

// Get topic counts for UI rendering
$counts = $this->contentSearch->getTopicsMetadata(['bitcoin', 'nostr', 'lightning']);
// Returns: ['bitcoin' => 142, 'nostr' => 89, 'lightning' => 56]

// Build a taxonomy with counts
$taxonomy = [
    'trading' => [
        'name' => 'Trading',
        'subcategories' => [
            'spot' => ['name' => 'Spot', 'tags' => ['spot', 'exchange']],
        ],
    ],
];
$enriched = $this->contentSearch->buildTaxonomyWithCounts($taxonomy);
// $enriched['trading']['subcategories']['spot']['count'] = 42
```

### Key Methods at a Glance

| Method | Use Case |
|--------|----------|
| `searchByTopics()` | Find articles by one or more topics (tags) |
| `findRelatedArticles()` | Find articles similar to a given article |
| `getTopicsMetadata()` | Get article counts for topics |
| `search()` | Free-text search across articles |
| `searchByAuthor()` | Get all articles by an author |
| `getLatest()` | Get newest articles (with optional author exclusion) |
| `buildTaxonomyWithCounts()` | Enrich topic hierarchies with counts |
| `deduplicateArticles()` | Remove duplicate articles (by pubkey + slug) |
| `isSearchAvailable()` | Check if search backend is reachable |

---

## Example 1: Display Related Articles on an Article Page

**Scenario**: You're rendering an article page and want to show "recommended" articles based on the current article's topics.

### Implementation

**Option A: In a Twig Live Component** (recommended)

```php
// src/Twig/Components/Organisms/RelatedArticles.php
namespace App\Twig\Components\Organisms;

use App\Entity\Article;
use App\Service\Search\ContentSearchService;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
class RelatedArticles
{
    use DefaultActionTrait;

    #[LiveProp]
    public Article $article;

    public array $articles = [];
    public array $errors = [];

    public function __construct(
        private readonly ContentSearchService $contentSearch,
    ) {}

    public function mount(): void
    {
        try {
            $this->articles = $this->contentSearch->findRelatedArticles(
                $this->article,
                limit: 6  // Show 6 related articles
            );
        } catch (\Throwable $e) {
            $this->errors[] = 'Failed to load related articles';
            $this->articles = [];
        }
    }
}
```

**Template** (`templates/components/Organisms/RelatedArticles.html.twig`):

```twig
{% if articles %}
    <section class="related-articles">
        <h3>{{ 'article.related_title'|trans }}</h3>
        <div class="articles-grid">
            {% for article in articles %}
                <twig:Molecules:Card
                    :article="article"
                    variant="compact"
                />
            {% endfor %}
        </div>
    </section>
{% endif %}

{% if errors %}
    <twig:Atoms:Alert type="warning" :messages="errors" />
{% endif %}
```

**Usage in article page template**:

```twig
{# In templates/article/show.html.twig #}
<article class="article-page">
    {# ... article body ... #}

    {# Related articles (lazy-loaded via Live Component) #}
    <twig:Organisms:RelatedArticles :article="article" />
</article>
```

**Option B: In a Controller**

```php
// If you prefer a traditional approach
#[Route('/p/{npub}/d/{slug}', name: 'article_show')]
public function show(
    string $npub,
    string $slug,
    ArticleRepository $repository,
    ContentSearchService $contentSearch,
): Response {
    $article = $repository->findByNpubAndSlug($npub, $slug);
    if (!$article) {
        throw $this->createNotFoundException();
    }

    // Get related articles
    $relatedArticles = $contentSearch->findRelatedArticles($article, limit: 6);

    return $this->render('article/show.html.twig', [
        'article' => $article,
        'relatedArticles' => $relatedArticles,
    ]);
}
```

---

## Example 2: Build a Topic Index with Counts

**Scenario**: You have a taxonomy of topics (categories → subcategories → tags) and want to display them with live article counts on an admin or browsing page.

### Implementation

```php
// src/Controller/TopicController.php
use App\Service\Search\ContentSearchService;
use App\Util\ForumTopics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class TopicController extends AbstractController
{
    public function __construct(
        private readonly ContentSearchService $contentSearch,
        private readonly CacheInterface $cache,
    ) {}

    #[Route('/topics', name: 'topics_index')]
    public function index(): Response
    {
        // Cache for 30 seconds (topics change slowly)
        $topicsWithCounts = $this->cache->get(
            'topics_index.v1',
            function (ItemInterface $item) {
                $item->expiresAfter(30);
                
                // One line builds the taxonomy with counts!
                return $this->contentSearch->buildTaxonomyWithCounts(
                    ForumTopics::TOPICS
                );
            }
        );

        return $this->render('topics/index.html.twig', [
            'topics' => $topicsWithCounts,
        ]);
    }
}
```

**Template** (`templates/topics/index.html.twig`):

```twig
<div class="topics-index">
    {% for catKey, category in topics %}
        <section class="topic-category">
            <h2>{{ category.name }}</h2>

            <div class="subcategories">
                {% for subKey, subcategory in category.subcategories %}
                    <article class="topic-card">
                        <h3>
                            <a href="{{ path('topic_view', {key: catKey ~ '-' ~ subKey}) }}">
                                {{ subcategory.name }}
                            </a>
                        </h3>
                        <p class="count">
                            {{ subcategory.count }}
                            {{ 'articles'|transchoice(subcategory.count) }}
                        </p>
                        <p class="description">{{ subcategory.description ?? '' }}</p>
                    </article>
                {% endfor %}
            </div>
        </section>
    {% endfor %}
</div>
```

---

## Example 3: Search Articles by Topic with Pagination

**Scenario**: A user selects one or more topics and you want to fetch and display matching articles with pagination.

### Implementation

```php
#[Route('/topics/{key}', name: 'topic_view')]
public function viewTopic(
    string $key,
    ContentSearchService $contentSearch,
    Request $request,
): Response {
    // Parse the topic key (e.g., "trading-spot" → tags from taxonomy)
    $tags = $this->getTagsForTopicKey($key);

    if (empty($tags)) {
        throw $this->createNotFoundException('Topic not found');
    }

    // Pagination
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    // Fetch more results than needed (for client-side duplication and filtering)
    $allArticles = $contentSearch->searchByTopics(
        $tags,
        limit: $perPage * 3,  // Fetch 60 to show 20 (buffer for filtering)
        offset: $offset
    );

    // Manual pagination (could use Pagerfanta)
    $articlesPage = array_slice($allArticles, 0, $perPage);

    return $this->render('topics/view.html.twig', [
        'topicName' => $tags[0],  // or look up from taxonomy
        'tags' => $tags,
        'articles' => $articlesPage,
        'page' => $page,
        'perPage' => $perPage,
    ]);
}

private function getTagsForTopicKey(string $key): array
{
    // Implementation depends on your taxonomy structure
    // For ForumTopics: extract tags from TOPICS constant
    [$catKey, $subKey] = explode('-', $key, 2) + [null];
    $topic = ForumTopics::TOPICS[$catKey]['subcategories'][$subKey] ?? null;
    return $topic['tags'] ?? [];
}
```

---

## Example 4: Refactor Existing Code Using ArticleSearchInterface

**Before** (using low-level interface):

```php
use App\Service\Search\ArticleSearchInterface;

class MyComponent
{
    public function __construct(
        private readonly ArticleSearchInterface $articleSearch,
    ) {}

    public function load(array $tags): void
    {
        // Manual normalization
        $normalizedTags = array_values(
            array_unique(
                array_map(
                    fn($t) => strtolower(trim((string) $t)),
                    $tags
                )
            )
        );

        // Fetch with boilerplate
        if (!$this->articleSearch->isAvailable()) {
            $this->articles = [];
            return;
        }

        try {
            $articles = $this->articleSearch->findByTopics($normalizedTags, 50, 0);
            // Manual deduplication
            $this->articles = $this->deduplicateArticles($articles);
        } catch (\Throwable $e) {
            $this->articles = [];
        }
    }

    private function deduplicateArticles(array $articles): array
    {
        // ... manual dedup logic ...
    }
}
```

**After** (using ContentSearchService):

```php
use App\Service\Search\ContentSearchService;

class MyComponent
{
    public function __construct(
        private readonly ContentSearchService $contentSearch,
    ) {}

    public function load(array $tags): void
    {
        // One clean line - no normalization, no error handling, no deduplication
        $this->articles = $this->contentSearch->searchByTopics($tags, limit: 50);
    }

    // No deduplicateArticles() method needed
}
```

**Key improvements**:
- Removed boilerplate normalization
- Removed error handling (method handles it)
- Removed deduplication helper
- More readable intent

---

## Example 5: Get Topic Metadata for UI

**Scenario**: You want to display popular topic statistics or topic suggestions.

### Implementation

```php
// In a component or controller
public function topicStats(ContentSearchService $contentSearch): Response
{
    // Get counts for specific topics
    $topics = ['bitcoin', 'ethereum', 'lightning', 'nostr', 'stacks'];
    
    $counts = $contentSearch->getTopicsMetadata($topics);
    // Returns: ['bitcoin' => 500, 'ethereum' => 350, ...]

    // Sort by popularity
    arsort($counts);

    return $this->render('dashboard/topic-stats.html.twig', [
        'topicStats' => $counts,
    ]);
}
```

**Template**:

```twig
<div class="topic-stats">
    <h3>{{ 'dashboard.topic_stats'|trans }}</h3>
    <ul class="topic-list">
        {% for topic, count in topicStats %}
            <li>
                <a href="{{ path('topic_view', {key: topic}) }}">
                    {{ topic|capitalize }}
                    <span class="badge">{{ count }}</span>
                </a>
            </li>
        {% endfor %}
    </ul>
</div>
```

---

## Common Pitfalls

### ❌ Pitfall 1: Forgetting Tag Normalization (Old Code)

```php
// WRONG: Relying on manual tag normalization
$tags = ['Bitcoin', 'LIGHTNING'];  // Mixed case!
$articles = $this->contentSearch->searchByTopics($tags);
```

✅ **Why it's OK with ContentSearchService**: The service normalizes tags automatically.

---

### ❌ Pitfall 2: Trying to Cache Individual Articles Without Deduplication

```php
// WRONG: Might get duplicates in cache
$cached = $this->cache->get('articles:topic', function () {
    return $this->contentSearch->searchByTopics(['bitcoin']);
});
```

✅ **Fix**: The service handles deduplication, but if you fetch multiple times, deduplicate before caching:

```php
$articles = $this->contentSearch->searchByTopics(['bitcoin']);
$unique = $this->contentSearch->deduplicateArticles(
    array_merge($cached_batch_1, $cached_batch_2)
);
```

---

### ❌ Pitfall 3: Handling Exceptions From Search

```php
// WRONG: Unnecessary try-catch
try {
    $articles = $this->contentSearch->searchByTopics($tags);
} catch (\Throwable $e) {
    $articles = [];
}
```

✅ **Correct**: The service catches exceptions internally, returns empty array.

```php
// RIGHT: No try-catch needed
$articles = $this->contentSearch->searchByTopics($tags);
if (empty($articles)) {
    // Render empty state
}
```

---

### ❌ Pitfall 4: Checking Availability on Every Call

```php
// WRONG: Overkill
if ($this->contentSearch->isSearchAvailable()) {
    $articles = $this->contentSearch->searchByTopics($tags);
}
```

✅ **Correct**: Only check if you want to alter behavior:

```php
// RIGHT: Only check if backend matters
if (!$this->contentSearch->isSearchAvailable()) {
    $this->showWarning('Search is slow, showing cached results');
}
$articles = $this->contentSearch->searchByTopics($tags);
```

---

## Testing

### Unit Testing with Mock

```php
use PHPUnit\Framework\TestCase;
use App\Service\Search\ContentSearchService;

class MyComponentTest extends TestCase
{
    public function testLoadsRelatedArticles(): void
    {
        // Mock the service
        $mockSearch = $this->createMock(ContentSearchService::class);
        $mockSearch
            ->expects($this->once())
            ->method('findRelatedArticles')
            ->with($article, 6)
            ->willReturn([/* article objects */]);

        $component = new MyComponent($mockSearch);
        $component->mount($article);

        $this->assertCount(6, $component->articles);
    }
}
```

### Integration Testing (Real Search)

```php
public function testSearchViaRealBackend(): void
{
    // Use the real container-injected service
    $contentSearch = self::getContainer()->get(ContentSearchService::class);

    // Actually search
    $articles = $contentSearch->searchByTopics(['bitcoin'], limit: 10);

    // Assert results
    $this->assertNotEmpty($articles);
    $this->assertCount(10, $articles);
}
```

### Testing with Different Backends

```bash
# Test with Elasticsearch enabled
ELASTICSEARCH_ENABLED=true php bin/phpunit

# Test with database only
ELASTICSEARCH_ENABLED=false php bin/phpunit
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| "Service not found" error | Ensure `ContentSearchService` is in `src/Service/Search/`. Should be auto-registered. |
| Empty results when expected data | Check tags are in database/Elasticsearch. Is search backend available? |
| Slow related articles loads | Cache results (30s-5min depending on frequency). Use `findRelatedArticles()` with smaller `limit`. |
| Testing returns empty results | Ensure seed data exists in test database. Check `ELASTICSEARCH_ENABLED` flag in test env. |

---

## Next Steps

1. **Read the full API ref**: `documentation/Search/content-search-api.md`
2. **Review migration guide**: `documentation/Search/migration-to-content-search-api.md`
3. **Try it out**: Refactor one component/controller using this skill
4. **Check the forum plan**: `documentation/Search/forum-deprecation-plan.md` for upcoming integration

---

## See Also

- `documentation/Search/content-search-api.md` — complete method reference
- `documentation/Search/migration-to-content-search-api.md` — refactoring guide
- `src/Service/Search/ContentSearchService.php` — source code (fully documented)
- `src/Service/Search/ArticleSearchInterface.php` — lower-level interface (for reference)

