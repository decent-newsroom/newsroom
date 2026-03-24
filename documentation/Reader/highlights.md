# Highlights

## Overview

Highlights are Nostr kind 9802 events (NIP-84) — user-selected excerpts from articles. They are fetched from relays, persisted to the database, and displayed on article pages and a dedicated highlights feed. Logged-in users can create highlights by selecting text in an article.

## Architecture

| Component | File |
|-----------|------|
| Entity | `src/Entity/Highlight.php` |
| Service | `src/Service/HighlightService.php` |
| Fetch command | `src/Command/FetchHighlightsCommand.php` |
| Cache command | `src/Command/CacheLatestHighlightsCommand.php` |
| Feed controller | `src/Controller/Reader/HighlightsController.php` |
| Publish controller | `src/Controller/HighlightController.php` |
| Stimulus controller | `assets/controllers/nostr/nostr_highlight_controller.js` |
| Toggle controller | `assets/controllers/ui/highlights_toggle_controller.js` |
| CSS | `assets/styles/04-pages/highlights.css` |

## Display

- **Article page sidebar**: Highlights shown in a collapsible sidebar section with the highlighter's profile
- **Article page mobile**: Highlights shown after comments on narrow screens
- **Inline highlighting**: Existing highlights are marked inline in the article text (toggled via button)
- **Highlights feed**: `/highlights` page shows latest highlights from Redis view store

## Creating Highlights

When a logged-in user selects text in an article:

1. A floating "Highlight" button appears near the selection
2. Clicking it triggers the `nostr--highlight` Stimulus controller
3. The controller builds a kind 9802 event per NIP-84:
   - `.content` = the selected text
   - `a` tag = `30023:<pubkey>:<slug>` (article coordinate)
   - `p` tag = article author pubkey with `author` role
   - `context` tag = the surrounding paragraph text (optional)
4. The event is signed via the user's Nostr signer (NIP-07/NIP-46)
5. The signed event is POSTed to `/highlights/publish`
6. The backend verifies the signature, publishes to the user's + author's relays, and persists locally for immediate display

## Coordinate Consistency

Highlights are stored with an `articleCoordinate` matching the format `30023:<pubkey>:<slug>`. All ingestion paths (cron fetch, async refresh, publish endpoint) extract the coordinate from the event's own `a` tag when available, ensuring consistency regardless of the entry point.

## Caching Strategy

Highlights use a two-tier read-only cache to minimise article page load latency. **No relay refresh is triggered during a web request** — article pages serve whatever is already cached.

1. **Redis (hot cache, 10 min TTL)**: `getHighlightsForArticle()` checks Redis first. On hit, the response is returned with zero DB queries.

2. **PostgreSQL (warm cache)**: On Redis miss, highlights are loaded via `findByArticleCoordinateDeduplicated()` — a DB-level dedup query (GROUP BY `MD5(content||pubkey)`) that avoids pulling duplicate rows into PHP. The result is written back to Redis for subsequent requests.

3. **Async ingestion (cron + relay workers)**: Highlights are fetched from relays and persisted to the database entirely in the background by cron jobs (`app:fetch-highlights` every 15 min, `app:cache-latest-highlights` every 30 min) and relay subscription workers. After saving new highlights the Redis key is invalidated so the next page load picks up fresh data.

**Compound index**: `idx_article_coordinate_cached_at` on `(article_coordinate, cached_at)` covers both aggregate and per-coordinate lookups in a single B-tree scan.

## Cron Schedule

- `*/15 min`: Fetch highlights from relays to database
- `*/30 min`: Cache latest highlights to Redis views

## Lessons Learned

- **Performance**: Highlights fetch was moved entirely out of the web request path. Article pages serve only from Redis/DB cache — no relay calls and no async message dispatches. Relay ingestion runs exclusively in cron jobs and relay subscription workers.
- **Feature gate removed**: The `HIGHLIGHTS_ENABLED` env flag was removed after the async refactor solved the original performance concern.
- **Coordinate mismatch bug**: Fixed an issue where `FetchHighlightsCommand` stored coordinates from the event's `a` tag, while `RefreshArticleHighlightsHandler` used the controller-supplied coordinate. Both now canonicalise from the event's `a` tag.
- **Redis cache layer**: Added a Redis → DB two-tier cache. On Redis hit (10 min TTL), article pages load with zero DB queries for highlights. On miss, a single DB query loads and re-warms Redis.
- **Query consolidation**: Replaced multiple DB queries per page load with a single `findByArticleCoordinateDeduplicated()` call using DB-level deduplication via `GROUP BY MD5(content||pubkey)`.
- **Multi-relay queries**: Highlights are now fetched from both the local strfry relay and default relays for broader coverage.
