# Unfold discovery documents

Registered Unfold subdomains expose publication-local discovery documents. These
routes are not available on the main newsroom domain or on unregistered hosts:

- `GET /rss.xml` returns the publication feed.
- `GET /feed.xml` returns a `308 Permanent Redirect` to `/rss.xml`.
- `GET /{category}/rss.xml` returns the feed for a known publication category.
- `GET /sitemap.xml` returns the publication sitemap.
- `GET /robots.txt` declares the publication sitemap.

All absolute links in these responses are built from the request host, so they
remain scoped to the publication's registered Unfold subdomain. RSS responses
use the RSS content type and include cache headers suitable for public,
publication-local feeds.

The publication feed is deterministic: it contains the newest 50 articles,
ordered newest first and deduplicated by article coordinate. An unknown category
returns `404 Not Found`.

The sitemap includes the publication home page, known category pages, and
article pages. It does not yet include future AppData `about` or `audiences`
documents. `robots.txt` points crawlers at the publication's `/sitemap.xml`.

Owner pages, footer behavior, and AppData documents remain separate Unfold work
and are not part of these discovery routes.
