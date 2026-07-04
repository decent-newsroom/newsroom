# Content Search API

## Overview

The `ContentSearchService` is a high-level, formal API for site-wide content discovery. It wraps the lower-level `ArticleSearchInterface` to provide a transparent abstraction layer for searching, filtering, and analyzing articles by topic, author, content, and more.

This service is the **primary API** for all content search operations across the newsroom. It abstracts away whether Elasticsearch or the database is being used as the backend, allowing seamless switching without affecting calling code.

## Key Principles

- **Backend-agnostic**: Works identically whether Elasticsearch or PostgreSQL is the search backend
- **Consistent normalization**: Tags/topics are automatically lowercased and trimmed
- **Graceful degradation**: Returns empty results on errors instead of throwing exceptions
- **Deduplication**: Articles are deduplicated by pubkey + slug to ensure unique author-article pairs
- **Taxonomy enrichment**: Supports enriching topic taxonomies with article counts for UI rendering

## Architecture

```
Controller/Component/Command
    ↓
ContentSearchService (high-level API)
    ↓
ArticleSearchInterface (factory-selected)
    ↓
ElasticsearchArticleSearch / DatabaseArticleSearch (implementations)
```

## API Methods

### `searchByTopics(array $topics, int $limit = 20, int $offset = 0): Article[]`

Search articles by one or more topics (tags).

**Parameters:**
- `$topics`: Array of topic/tag names (e.g., `['bitcoin', 'lightning']`)
- `$limit`: Maximum results (default: 20)
- `$offset`: Pagination offset (default: 0)

**Returns:** Array of matching articles, OR-ed together (any topic match).

**Behavior:**
- Topics are normalized (lowercase, trimmed, deduplicated)
- Results are sorted by creation date (newest first)
- Results are deduplicated by pubkey + slug
- Empty topic array returns empty results
- On error, returns empty array and logs the failure

**Example:**

```php
$articles = $this->contentSearch->searchByTopics(
    ['bitcoin', 'lightning'],
    limit: 20,
    offset: 0
);
```

**Use cases:**
- Topic page content
- Search filter results
- Interest-based feeds
- Category listings

---

### `findRelatedArticles(Article $article, int $limit = 6): Article[]`

Find articles related to a given article.

**Parameters:**
- `$article`: The reference article
- `$limit`: Maximum results (default: 6)

**Returns:** Array of related articles, excluding the input article itself.

**Behavior:**
- Extracts tags from the input article
- Searches for articles sharing those tags
- Filters out the input article (by pubkey + slug)
- Fetches up to `$limit * 2` and returns the best `$limit`
- If the article has no tags, returns empty array
- On error, returns empty array and logs the failure

**Example:**

```php
$related = $this->contentSearch->findRelatedArticles($currentArticle, limit: 6);
```

**Use cases:**
- "Related articles" sections on article pages
- Content recommendation sidebars
- Cross-linking between similar topics

---

### `getTopicsMetadata(array $topics): array<string, int>`

Get article counts for topics.

**Parameters:**
- `$topics`: Array of topic/tag names

**Returns:** Associative array mapping topic name → article count.

**Behavior:**
- Topics are normalized (lowercase, trimmed, deduplicated)
- Returns counts for all requested topics (even if count is 0)
- On error, returns empty array and logs the failure

**Example:**

```php
$counts = $this->contentSearch->getTopicsMetadata(
    ['bitcoin', 'nostr', 'lightning']
);
// Returns: ['bitcoin' => 142, 'nostr' => 89, 'lightning' => 56]
```

**Use cases:**
- Topic index rendering (showing popularity)
- Topic list filtering (hiding empty topics)
- Analytics and statistics
- Category count badges

---

### `search(string $query, int $limit = 12, int $offset = 0): Article[]`

Full-text search by free-text query.

**Parameters:**
- `$query`: Search keywords/phrase
- `$limit`: Maximum results (default: 12)
- `$offset`: Pagination offset (default: 0)

**Returns:** Array of matching articles.

**Behavior:**
- Query is trimmed; empty queries return empty results
- Searches across article titles, summaries, and content
- When Elasticsearch is enabled: supports phrase matching and fuzzy matching
- When database only: uses full-text search via SQL
- Results sorted by relevance (Elasticsearch) or creation date (database)
- On error, returns empty array and logs the failure

**Example:**

```php
$results = $this->contentSearch->search('lightning network', limit: 12);
```

**Use cases:**
- Homepage search box
- General content discovery
- Search page results

---

### `searchByAuthor(string $pubkeyHex, int $limit = 20, int $offset = 0): Article[]`

Find all published articles by a given author.

**Parameters:**
- `$pubkeyHex`: Author's hex-encoded public key
- `$limit`: Maximum results (default: 20)
- `$offset`: Pagination offset (default: 0)

**Returns:** Array of articles by that author.

**Behavior:**
- Pubkey is normalized (trimmed, lowercase)
- Empty pubkey returns empty results
- Results sorted by creation date (newest first)
- Only published articles (excludes drafts)
- On error, returns empty array and logs the failure

**Example:**

```php
$articles = $this->contentSearch->searchByAuthor($authorPubkeyHex, limit: 20);
```

**Use cases:**
- Author profile pages
- "More from this author" sections
- Author archive listings

---

### `getLatest(int $limit = 50, array $excludedPubkeys = []): Article[]`

Get the latest published articles.

**Parameters:**
- `$limit`: Maximum results (default: 50)
- `$excludedPubkeys`: Author pubkeys to exclude (e.g., bots)

**Returns:** Array of newest articles.

**Behavior:**
- Excluded pubkeys are normalized (lowercased, deduplicated)
- Results sorted by creation date (newest first)
- Excludes drafts automatically
- On error, returns empty array and logs the failure

**Example:**

```php
// Get latest 20 articles
$latest = $this->contentSearch->getLatest(limit: 20);

// Get latest articles, excluding certain authors
$filtered = $this->contentSearch->getLatest(
    limit: 20,
    excludedPubkeys: ['pubkey1', 'pubkey2']
);
```

**Use cases:**
- Homepage feeds
- "Latest articles" sections
- Trending content (with bot filtering)
- Recent activity feeds

---

### `buildTaxonomyWithCounts(array $taxonomy): array`

Enrich a topic taxonomy structure with article counts.

**Parameters:**
- `$taxonomy`: Taxonomy structure with categories and subcategories:
  ```php
  [
      'category-key' => [
          'name' => 'Category Name',
          'subcategories' => [
              'subcategory-key' => [
                  'name' => 'Subcategory Name',
                  'tags' => ['tag1', 'tag2', ...],
              ],
              ...
          ]
      ],
      ...
  ]
  ```

**Returns:** Same taxonomy structure with 'count' field added to each subcategory.

**Behavior:**
- Collates all unique tags from the taxonomy
- Fetches counts for all tags in a single batch query
- Injects counts into each subcategory
- Returns taxonomy with empty counts (0) if search unavailable
- On error, returns taxonomy with all zeros and logs the failure

**Example:**

```php
$taxonomy = [
    'trading' => [
        'name' => 'Trading',
        'subcategories' => [
            'spot' => [
                'name' => 'Spot Trading',
                'tags' => ['spot', 'exchange'],
            ],
        ],
    ],
];

$enriched = $this->contentSearch->buildTaxonomyWithCounts($taxonomy);
// $enriched['trading']['subcategories']['spot']['count'] = 42
```

**Use cases:**
- Topic index pages
- Sidebar navigation menus
- Topic directory renderings
- Forum category listings (with live counts)

---

### `deduplicateArticles(array $articles): Article[]`

Remove duplicate articles by pubkey + slug.

**Parameters:**
- `$articles`: Array of Article objects

**Returns:** Deduplicated array, keeping first occurrence of each pubkey+slug pair.

**Behavior:**
- Preserves article order (first occurrence wins)
- Effective against multiple versions of same article from different publishing events
- Safe no-op if articles are already unique

**Example:**

```php
$articles = $this->contentSearch->searchByTopics(['bitcoin'], limit: 100);
$unique = $this->contentSearch->deduplicateArticles($articles);
```

**Use cases:**
- Deduplating search results
- Removing publication duplicates
- Ensuring unique author-article pairs in lists

---

### `isSearchAvailable(): bool`

Check if the search backend is available.

**Returns:** `true` if backend is reachable, `false` otherwise.

**Behavior:**
- Returns `false` if Elasticsearch is configured but unreachable
- Database backend always returns `true`
- On unexpected error, returns `false` and logs warning

**Example:**

```php
if ($this->contentSearch->isSearchAvailable()) {
    $results = $this->contentSearch->searchByTopics($tags);
} else {
    // Fallback or graceful degradation
}
```

**Use cases:**
- Conditional rendering of search-dependent features
- Admin dashboards (reporting search health)
- Fallback logic

---

## Error Handling

All methods follow a consistent error-handling strategy:

1. **Silent failures**: Exceptions are caught and logged at appropriate levels
2. **Empty fallback**: Methods return empty results on error (not exceptions)
3. **Logging**: All failures are logged with context (e.g., search query, topics, error message)
4. **Caller transparency**: Callers don't need to handle exceptions from the search layer

**Example error flow:**

```php
// This never throws an exception:
$results = $this->contentSearch->searchByTopics(['bitcoin']);

// If Elasticsearch is down, you get an empty array and a logged error:
// - Error level: ["topics" => ["bitcoin"], "error" => "Connection refused"]
```

---

## Backend Selection

The search backend is selected at application startup based on the `ELASTICSEARCH_ENABLED` environment variable:

- **`ELASTICSEARCH_ENABLED=true`**: Uses Elasticsearch for all queries (faster, more features)
- **`ELASTICSEARCH_ENABLED=false`**: Uses PostgreSQL database (always available, simpler)

The `ContentSearchService` works identically regardless of backend. Switching requires only an environment variable change and application reload.

## Performance Considerations

### Elasticsearch Backend
- **Strengths**: Fast complex queries, fuzzy matching, phrase matching, large datasets
- **Overhead**: Network latency, index refresh interval (default ~1s)
- **Typical latency**: 10-50ms for simple queries

### Database Backend
- **Strengths**: No external dependency, simpler ops
- **Overhead**: Full-table scans for tag queries, JSONB containment checks
- **Typical latency**: 50-200ms depending on table size and indexes

### Optimization Tips

1. **Batch tag lookups**: Fetch all topic counts in one call to `getTopicsMetadata()` instead of per-topic
2. **Pagination**: Use `$limit` and `$offset` to avoid loading unnecessary results
3. **Topic deduplication**: Pass unique, normalized topics to reduce backend load
4. **Caching**: Cache taxonomy enrichment (e.g., 30s) for frequently accessed category pages

---

## Integration Examples

### Forum Topic Page

**Before (using `ArticleSearchInterface` directly):**

```php
public function topic(string $key, ArticleSearchInterface $articleSearch): Response
{
    [$cat, $sub] = explode('-', $key, 2);
    $tags = ForumTopics::TOPICS[$cat]['subcategories'][$sub]['tags'];
    $articles = $articleSearch->findByTopics($tags, 20, 0);
    // ... rendering
}
```

**After (using `ContentSearchService`):**

```php
public function topic(string $key, ContentSearchService $contentSearch): Response
{
    [$cat, $sub] = explode('-', $key, 2);
    $tags = ForumTopics::TOPICS[$cat]['subcategories'][$sub]['tags'];
    $articles = $contentSearch->searchByTopics($tags, limit: 20);
    // ... rendering (simpler, more readable)
}
```

### Article Page - Related Articles Section

**Integration:**

```php
// In ArticleController::show or a Twig Live Component
public function relatedArticles(Article $article, ContentSearchService $contentSearch): Response
{
    $related = $contentSearch->findRelatedArticles($article, limit: 6);
    // Render related articles grid
}
```

### Homepage Feed with Topic Metadata

**Integration:**

```php
// In HomeFeedController
public function topicThemes(ContentSearchService $contentSearch): Response
{
    $taxonomy = ForumTopics::TOPICS;
    $enriched = $contentSearch->buildTaxonomyWithCounts($taxonomy);
    
    // Render topic grid with live counts
}
```

### Navigation Builder with Topic Counts

**Integration:**

```php
// In NavigationBuilderTrait or similar
private function buildTopicNav(ContentSearchService $contentSearch): array
{
    $taxonomy = ForumTopics::TOPICS;
    $enriched = $contentSearch->buildTaxonomyWithCounts($taxonomy);
    
    // Filter hidden categories, sort by popularity, etc.
    return $this->formatForNav($enriched);
}
```

---

## Deprecation Path: Forum to Feeds

The `ContentSearchService` enables a seamless migration from the forum-focused architecture to a feed-integrated one:

### Phase 1: Parallel Rendering
- Forum pages continue to work using `ContentSearchService`
- Topic widgets appear on main feed and article pages
- Users discover topics in both old and new locations

### Phase 2: Feed Integration
- Topic sections integrated into home feed
- Article pages show related articles + topic suggestions
- Admin panel offers topic/tag UI (replacing forum management)

### Phase 3: Deprecation
- Forum index (`/forum`) deprecated (→ redirect to topic feed)
- Topic pages (`/forum/topic/{key}`) deprecated (→ integrated search)
- Legacy forum routes removed

**No breaking changes**: All search functionality is preserved; only routes and UI change.

---

## Testing

Test the `ContentSearchService` by injecting it into commands:

```bash
# Test search availability
docker compose exec php bin/console app:test-search-service

# Rebuild search indexes
docker compose exec php bin/console fos:elastica:populate

# Switch backend (edit .env)
ELASTICSEARCH_ENABLED=false
docker compose restart php
```

---

## Troubleshooting

### Search returns no results
1. Check if Elasticsearch is enabled: `grep ELASTICSEARCH_ENABLED .env`
2. Verify articles are indexed: `docker compose exec php bin/console fos:elastica:populate`
3. Check backend availability: `$contentSearch->isSearchAvailable()`

### Performance degradation
1. Switch to database backend if Elasticsearch is slow
2. Check topic count caching (forum index)
3. Monitor Elasticsearch cluster health

### Topics not appearing in metadata
1. Ensure topics are lowercase in searches
2. Verify articles have the 't' tags populated
3. Check Elasticsearch index mapping if using ES

---

## API Versioning

This API is stable. Future changes will:
- Add new methods, never remove existing ones
- Change return types only with major version bumps
- Maintain backward compatibility with existing code


