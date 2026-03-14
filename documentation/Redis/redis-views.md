# Redis View Store

## Overview

The Redis view store enables rendering pages (latest articles, highlights, user profiles) with a **single Redis GET** per page. Typed, immutable DTOs combine articles, highlights, and profiles into pre-composed JSON structures stored in Redis.

## Architecture

```
Nostr Events → Entities (PostgreSQL) → RedisViewFactory → RedisViewStore → Redis
                                                                              │
                                                              Single GET per page
                                                                              │
                                                                         Controllers → Templates
```

### DTO Hierarchy (`src/ReadModel/RedisView/`)

```
RedisBaseObject
├── article: RedisArticleView     (kind 30023 fields)
├── highlight: RedisHighlightView (kind 9802, optional)
├── author: RedisProfileView      (kind 0)
├── profiles: Map<pubkey, RedisProfileView>
└── meta: array                   (extensible: zaps, counts, etc.)
```

| Class | Purpose |
|-------|---------|
| `RedisProfileView` | Profile (kind 0): pubkey, displayName, nip05, pictureUrl, about, website, lud16, banner |
| `RedisArticleView` | Article (kind 30023): id, eventId, pubkey, slug, title, summary, image, publishedAt, tags, etc. |
| `RedisHighlightView` | Highlight (kind 9802): eventId, pubkey, createdAt, content, context, refs |
| `RedisReadingListView` | Reading list view for curation sets |
| `RedisBaseObject` | Composite wrapper combining the above |
| `RedisViewFactory` | Converts entities to views: `profileToView()`, `articleToView()`, `highlightToView()`, `articleBaseObject()` |

### Redis Keys

| Key | Contents | TTL |
|-----|----------|-----|
| `view:articles:latest` | Array of article base objects | 1 hour |
| `view:highlights:latest` | Array of highlight base objects | 1 hour |
| `view:user:articles:<pubkey>` | Array of user's article base objects | 1 hour |

### Storage & Retrieval

- **`RedisViewStore`** (`src/Service/Cache/RedisViewStore.php`) — stores and fetches view arrays, handles JSON serialization
- **`CacheLatestArticlesCommand`** — cron job rebuilds `view:articles:latest` every 15 minutes
- **`CacheLatestHighlightsCommand`** — cron job rebuilds `view:highlights:latest` every 30 minutes
- Controllers read from Redis first, fall back to database on cache miss

### DTOs Match Template Expectations

DTOs were redesigned so property names match exactly what Twig templates expect — no mapping layer needed in controllers. For example, `RedisArticleView::$image` maps directly to `{{ article.image }}` in templates.

## Lessons Learned

- **`stdClass` from Redis**: `RedisCacheService::getMetadata()` returns `stdClass`, not arrays. `RedisViewFactory::profileToView()` accepts `array|\stdClass|null` and converts internally.
- **Array fields**: `nip05` and `lud16` are stored as arrays in `RedisCacheService` (to handle multiple values from tags). The factory extracts the first element: `is_array($value) ? ($value[0] ?? null) : $value`.
- **Profile cache invalidation**: Views have a TTL-based expiry rather than event-driven invalidation, since profiles change infrequently.

