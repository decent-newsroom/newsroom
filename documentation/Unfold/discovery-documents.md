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

The publication feed is deterministic: it contains the newest 50 supported
content items, ordered newest first and deduplicated by full coordinate.
Supported kinds are articles (`30023`), chapters (`30041`), wiki entries
(`30818`), and community-authored NIPs (`30817`). An unknown category returns
`404 Not Found`.

Content links use `/{npub}/a/{dtag}`, `/{npub}/chapter/{dtag}`,
`/{npub}/wiki/{dtag}`, or `/{npub}/spec/{dtag}`. All discovery and theme links
share these author-qualified canonical paths, avoiding collisions between
authors or kinds. Scoped content is excluded from public discovery output;
these documents do not grant access or implement gated-content authorization.

The sitemap includes the publication home page, known category pages, and
supported public content pages. Audience offer pages remain deferred.
`robots.txt` points crawlers at the publication's `/sitemap.xml`.

See [publication administration](publication-admin.md) for assignment and
multi-kind rendering. Payment services and a future portable definition remain
separate work and are not part of these discovery routes.
