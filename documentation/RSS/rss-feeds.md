# RSS Feeds

## Overview

RSS feed generation for magazines and reading lists, and RSS-to-Nostr import for administrators. Allows external readers to subscribe to content published on the platform, and allows admins to import articles from any RSS or Atom feed as Nostr longform events.

## Features

- Magazine-level RSS feeds at `/mag/{slug}/rss.xml`
- Category-level feeds within magazines
- RSS-to-Nostr import admin page at `/admin/rss`

## RSS Import Flow

1. **Fetch**: Admin enters an RSS/Atom feed URL → backend fetches and parses the feed
2. **Select**: Articles are shown with duplicate detection (already-imported items marked). Admin selects which to import.
3. **Review**: Selected articles are converted to unsigned kind 30023 event skeletons (HTML → Markdown content, tags for title/summary/image/categories/source URL)
4. **Sign & Publish**: Each skeleton is signed client-side via the admin's Nostr signer (NIP-07 extension or NIP-46 bunker), then POSTed to the backend which persists locally and publishes to relays.

Batch signing processes articles sequentially with per-article progress and relay feedback.

## Key Files

| Component | File |
|-----------|------|
| RSS service | `src/Service/RSS/RssFeedService.php` |
| Tag matching | `src/Service/RSS/TagMatchingService.php` |
| Admin controller | `src/Controller/Administration/AdminRssController.php` |
| Submit template | `templates/admin/rss_submit.html.twig` |
| Review template | `templates/admin/rss_review.html.twig` |
| Batch sign JS | `assets/controllers/nostr/rss_batch_sign_controller.js` |
| Admin CSS | `assets/styles/04-pages/admin.css` |

## Lessons Learned

- **Category index fix**: RSS feeds for magazine categories must look up the category by its `d-tag` within the magazine's index event, not by a standalone slug.
- **Client-side signing**: RSS import uses the same NIP-07/NIP-46 client-side signing pattern as the article editor — never server-side signing with a stored private key.
