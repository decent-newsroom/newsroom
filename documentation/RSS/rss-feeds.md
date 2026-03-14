# RSS Feeds

## Overview

RSS feed generation for magazines and reading lists. Allows external readers to subscribe to content published on the platform.

## Features

- Magazine-level RSS feeds at `/mag/{slug}/rss.xml`
- Category-level feeds within magazines
- RSS-to-Nostr converter for importing external RSS content

## Key Files

| Component | File |
|-----------|------|
| RSS service | `src/Service/RSS/RssFeedService.php` |
| RSS converter | `src/Service/RSS/RssToNostrConverter.php` |
| Tag matching | `src/Service/RSS/TagMatchingService.php` |
| Admin controller | `src/Controller/Administration/AdminRssController.php` |

## Lessons Learned

- **Category index fix**: RSS feeds for magazine categories must look up the category by its `d-tag` within the magazine's index event, not by a standalone slug.

