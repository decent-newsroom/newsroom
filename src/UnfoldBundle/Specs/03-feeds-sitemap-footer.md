# Feeds, Sitemap, Robots, And Footer

## Goal

Each Unfold publication should expose machine-readable discovery endpoints using publication-local URLs. The default theme footer should distinguish DN platform links from publication-owner links.

## Routes

Add explicit Unfold routes before the catch-all site controller:

- `GET /rss.xml`: publication RSS feed.
- `GET /feed.xml`: alias for `/rss.xml`.
- `GET /{category}/rss.xml`: category RSS feed.
- `GET /sitemap.xml`: publication sitemap.
- `GET /robots.txt`: optional publication robots response.

These routes must run before `RouteMatcher` static-file rejection, because `.xml` and `.txt` paths are currently considered static-like and would otherwise 404.

## RSS Feed

Publication feed content:

- Channel title, description, logo, and link come from `SiteConfig`/AppData-derived context.
- Items use publication-local absolute URLs like `https://<subdomain>.<base-domain>/a/<slug>`.
- Item title, summary, image, author, published date, and GUID come from `PostData`.
- GUID should be the article coordinate when available.
- Limit initial feed size to a practical cap such as the newest 50 publication posts.
- Deduplicate by article coordinate.

Category feed content:

- Same structure as publication feed.
- Channel title is `<category title> - <publication title>`.
- Items are only the posts in the matched category.
- Unknown categories return 404.

Response headers:

- `Content-Type: application/rss+xml; charset=UTF-8`
- `Cache-Control: public, max-age=600`

## Sitemap

The publication sitemap includes:

- Home page `/`.
- About page if AppData has an `about` coordinate and the route is implemented.
- Category pages from the publication index.
- Article pages from all category descendants.
- RSS/feed URLs may be listed with low priority only if useful for crawler discovery.

All `loc` values must be absolute URLs for the current Unfold host. Do not use main-domain `/mag` or `/p` routes.

Response headers:

- `Content-Type: application/xml; charset=UTF-8`
- `Cache-Control: public, max-age=600`

## Robots

`/robots.txt` is optional but recommended for crawler discovery:

```txt
User-agent: *
Allow: /
Sitemap: https://<publication-host>/sitemap.xml
```

If a future owner setting disables indexing, robots may emit `Disallow: /`, but that setting is out of scope for the first implementation.

## Footer

The default Unfold footer becomes two levels:

Publication level:

- Publication title.
- Publication navigation links.
- About link when AppData has an `about` article.
- RSS link.
- Audience/subscription link when audiences exist.
- Publication payment/tip link when payment targets exist.

DN level:

- Powered by Unfold / Decent Newsroom.
- DN legal/platform links.
- DN sitemap link only if pointing at the main domain.

Theme context should expose footer sections separately, for example:

```php
'publication_footer' => [...],
'dn_footer' => [...],
```

Templates should not infer publication-owner links from DN platform links.