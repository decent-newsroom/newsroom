# Highlights

## Overview

Highlights are Nostr kind 9802 events — user-selected excerpts from articles. They are fetched from relays, persisted to the database, and displayed on article pages and a dedicated highlights feed.

## Architecture

| Component | File |
|-----------|------|
| Entity | `src/Entity/Highlight.php` |
| Service | `src/Service/HighlightService.php` |
| Fetch command | `src/Command/FetchHighlightsCommand.php` |
| Cache command | `src/Command/CacheLatestHighlightsCommand.php` |
| Controller | `src/Controller/Reader/HighlightsController.php` |

## Display

- **Article page**: Highlights shown in a collapsible sidebar section, with Nostr preview cards for the highlight authors
- **Highlights feed**: `/highlights` page shows latest highlights from Redis view store

## Cron Schedule

- `*/15 min`: Fetch highlights from relays to database
- `*/30 min`: Cache latest highlights to Redis views

## Lessons Learned

- **Performance**: Highlights fetch was moved to async (background cron) rather than inline during page load. This eliminated slow article page renders when relay connections were slow.
- **TTL**: Caching TTLs that were too short caused empty highlights lists between cache rebuilds. Extended to 1 hour.

