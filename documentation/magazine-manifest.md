# Magazine Manifest API

## Overview

The Magazine Manifest API provides machine-readable JSON endpoints that expose the complete structure of magazines and the newsstand catalog. These predictable routes make magazines easily discoverable and parseable by crawlers, AI agents, and external tools.

## Endpoints

### 1. Global Magazines Catalog

**Endpoint**: `/magazines/manifest.json`  
**Route Name**: `magazines-manifest`  
**Schema Type**: `DataCatalog`

Lists all available magazines with their basic metadata.

#### Example Request
```bash
curl https://your-site.com/magazines/manifest.json
```

#### Response Structure
```json
{
  "@context": "https://schema.org",
  "@type": "DataCatalog",
  "name": "Newsroom Magazines",
  "description": "Collection of all magazines available on Newsroom",
  "version": "1.0",
  "generatedAt": "2026-02-12T10:30:00Z",
  "url": "https://your-site.com/newsstand",
  "dataset": [
    {
      "slug": "tech-weekly",
      "title": "Tech Weekly",
      "summary": "Weekly technology news and insights",
      "image": "https://example.com/cover.jpg",
      "language": "en",
      "pubkey": "abc123...",
      "createdAt": "2026-01-15T12:00:00Z",
      "categoryCount": 5,
      "url": "https://your-site.com/mag/tech-weekly",
      "manifestUrl": "https://your-site.com/mag/tech-weekly/manifest.json"
    }
  ],
  "stats": {
    "totalMagazines": 10,
    "totalCategories": 45
  }
}
```

#### Fields

| Field | Type | Description |
|-------|------|-------------|
| `@context` | string | Schema.org context |
| `@type` | string | Always "DataCatalog" |
| `version` | string | API version |
| `generatedAt` | ISO-8601 | Timestamp of generation |
| `dataset` | array | Array of magazine objects |
| `stats` | object | Aggregate statistics |

#### Magazine Object Fields

| Field | Type | Description |
|-------|------|-------------|
| `slug` | string | URL-friendly magazine identifier |
| `title` | string | Magazine title |
| `summary` | string | Magazine description |
| `image` | string | Cover image URL |
| `language` | string | Language code (e.g., "en") |
| `pubkey` | string | Nostr public key of publisher |
| `createdAt` | ISO-8601 | Creation timestamp |
| `categoryCount` | integer | Number of categories |
| `url` | string | Magazine front page URL |
| `manifestUrl` | string | Link to detailed manifest |

#### Cache
- **Cache-Control**: `public, max-age=600` (10 minutes)
- Safe to cache aggressively as this changes infrequently

---

### 2. Individual Magazine Manifest

**Endpoint**: `/mag/{slug}/manifest.json`  
**Route Name**: `magazine-manifest`  
**Schema Type**: `Periodical`

Provides complete structure of a specific magazine including all categories and articles.

#### Example Request
```bash
curl https://your-site.com/mag/tech-weekly/manifest.json
```

#### Response Structure
```json
{
  "@context": "https://schema.org",
  "@type": "Periodical",
  "version": "1.0",
  "generatedAt": "2026-02-12T10:30:00Z",
  "magazine": {
    "id": "123",
    "slug": "tech-weekly",
    "title": "Tech Weekly",
    "summary": "Weekly technology news and insights",
    "image": "https://example.com/cover.jpg",
    "language": "en",
    "pubkey": "abc123...",
    "createdAt": "2026-01-15T12:00:00Z",
    "url": "https://your-site.com/mag/tech-weekly"
  },
  "categories": [
    {
      "slug": "ai-news",
      "title": "AI News",
      "summary": "Latest developments in artificial intelligence",
      "image": "https://example.com/ai-cover.jpg",
      "url": "https://your-site.com/mag/tech-weekly/cat/ai-news",
      "articleCount": 12,
      "articles": [
        {
          "title": "GPT-5 Released",
          "slug": "gpt-5-released",
          "summary": "OpenAI announces GPT-5 with breakthrough capabilities",
          "image": "https://example.com/article-image.jpg",
          "pubkey": "def456...",
          "createdAt": "2026-02-10T08:00:00Z",
          "publishedAt": "2026-02-11T10:00:00Z",
          "topics": ["AI", "OpenAI", "GPT"],
          "url": "https://your-site.com/mag/tech-weekly/cat/ai-news/gpt-5-released"
        }
      ]
    }
  ],
  "stats": {
    "totalCategories": 5,
    "totalArticles": 47
  }
}
```

#### Fields

| Field | Type | Description |
|-------|------|-------------|
| `magazine` | object | Magazine metadata |
| `categories` | array | Array of category objects |
| `stats` | object | Aggregate statistics |

#### Category Object Fields

| Field | Type | Description |
|-------|------|-------------|
| `slug` | string | Category identifier |
| `title` | string | Category title |
| `summary` | string | Category description |
| `image` | string | Category image URL |
| `url` | string | Category page URL |
| `articleCount` | integer | Number of articles |
| `articles` | array | Array of article objects |

#### Article Object Fields

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Article headline |
| `slug` | string | Article identifier |
| `summary` | string | Article summary |
| `image` | string | Cover image URL |
| `pubkey` | string | Author's Nostr public key |
| `createdAt` | ISO-8601 | Creation timestamp |
| `publishedAt` | ISO-8601 | Publication timestamp |
| `topics` | array | Article topics/tags |
| `url` | string | Full article URL |

#### Cache
- **Cache-Control**: `public, max-age=300` (5 minutes)
- Balances freshness with performance

---

## Discoverability

### HTML Link Tags

Manifests are discoverable via `<link>` tags in HTML pages:

#### Newsstand Page
```html
<link rel="alternate" 
      type="application/json" 
      href="/magazines/manifest.json" 
      title="Magazines Catalog">
```

#### Magazine Front Page
```html
<link rel="alternate" 
      type="application/json" 
      href="/mag/tech-weekly/manifest.json" 
      title="Magazine Manifest">
```

### JSON-LD References

Manifests are also referenced in JSON-LD structured data:

```json
{
  "@type": "Periodical",
  "distribution": {
    "@type": "DataDownload",
    "encodingFormat": "application/json",
    "contentUrl": "https://your-site.com/mag/tech-weekly/manifest.json"
  }
}
```

---

## Use Cases

### 1. AI/LLM Agents
Agents can:
- Discover all available magazines
- Parse complete magazine structure
- Extract article metadata for analysis
- Build knowledge graphs

### 2. External Aggregators
Third-party services can:
- Index magazine catalogs
- Sync content periodically
- Build magazine directories
- Create RSS-like feeds

### 3. Search Engines
Crawlers can:
- Efficiently discover all content
- Understand content hierarchy
- Extract structured metadata
- Build sitemaps programmatically

### 4. Analytics Tools
Analysis tools can:
- Track magazine growth
- Measure content coverage
- Compare magazine structures
- Generate reports

### 5. Mobile/Desktop Apps
Applications can:
- Fetch offline content lists
- Build navigation trees
- Cache magazine structures
- Sync content updates

---

## Integration Examples

### JavaScript/TypeScript
```typescript
// Fetch all magazines
const response = await fetch('/magazines/manifest.json');
const catalog = await response.json();

// Iterate through magazines
for (const mag of catalog.dataset) {
  console.log(`${mag.title}: ${mag.categoryCount} categories`);
  
  // Fetch detailed manifest
  const magResponse = await fetch(mag.manifestUrl);
  const magData = await magResponse.json();
  
  // Process categories and articles
  for (const cat of magData.categories) {
    console.log(`  - ${cat.title}: ${cat.articles.length} articles`);
  }
}
```

### Python
```python
import requests

# Fetch magazines catalog
catalog = requests.get('https://your-site.com/magazines/manifest.json').json()

# Process each magazine
for mag in catalog['dataset']:
    print(f"{mag['title']}: {mag['categoryCount']} categories")
    
    # Fetch detailed manifest
    manifest = requests.get(mag['manifestUrl']).json()
    
    # Extract all article URLs
    urls = [
        article['url'] 
        for category in manifest['categories'] 
        for article in category['articles']
    ]
    print(f"  Total articles: {len(urls)}")
```

### curl (Shell)
```bash
# List all magazines
curl -s https://your-site.com/magazines/manifest.json | jq '.dataset[].title'

# Get specific magazine structure
curl -s https://your-site.com/mag/tech-weekly/manifest.json | jq '.categories[].title'

# Extract all article URLs from a magazine
curl -s https://your-site.com/mag/tech-weekly/manifest.json | \
  jq -r '.categories[].articles[].url'
```

---

## Error Handling

### 404 - Magazine Not Found
```json
{
  "error": "Magazine not found"
}
```

### 500 - Server Error
```json
{
  "error": "Failed to generate manifest",
  "message": "Detailed error message"
}
```

---

## Best Practices

### For Consumers

1. **Cache Appropriately**: Respect `Cache-Control` headers
2. **Handle Errors**: Implement retry logic with exponential backoff
3. **Check `generatedAt`**: Determine data freshness
4. **Validate Structure**: Don't assume all fields are present
5. **Follow Links**: Use provided URLs rather than constructing them

### For Rate Limiting

- Global catalog: Can be fetched frequently (10min cache)
- Individual manifests: Moderate frequency (5min cache)
- Implement client-side caching
- Use conditional requests (ETag, If-Modified-Since)

### For Processing

1. **Pagination**: Not currently implemented but may be added
2. **Filtering**: Build client-side filters on fetched data
3. **Sorting**: Data is pre-sorted but can be re-sorted client-side
4. **Searching**: Use article topics and summaries for search

---

## Future Enhancements

Potential additions to the API:

- **Pagination**: For large magazine catalogs
- **Filtering**: Query parameters for language, topics, etc.
- **Incremental Updates**: Delta endpoints for sync
- **Stats Expansion**: More detailed analytics
- **Media Manifests**: Separate manifests for images/videos
- **Author Manifests**: Per-author content catalogs
- **Versioning**: Support for multiple API versions
- **WebSub**: Push notifications for updates
- **GraphQL**: Alternative query interface

---

## Schema.org Compliance

All manifests use Schema.org types:

- **DataCatalog**: For the global magazines list
- **Periodical**: For individual magazines
- **NewsArticle**: For individual articles (in article lists)
- **Thing**: For topics and categories

This ensures compatibility with:
- Google Knowledge Graph
- Microsoft Bing
- Schema.org validators
- RDF processors

---

## Related Documentation

- [JSON-LD Implementation](./json-ld-structured-data.md)
- [JSON-LD Testing Guide](./json-ld-testing-guide.md)
- [API Reference](../docs/api-reference.md) (if exists)

---

## Support

For issues or questions:
1. Check response error messages
2. Validate JSON structure
3. Review Schema.org documentation
4. Check server logs for detailed errors

