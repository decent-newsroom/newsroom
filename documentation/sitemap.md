# Sitemap

## Overview

Newsroom exposes a standard XML sitemap at `/sitemap.xml` to help search-engine crawlers discover all publicly indexable content.

## Endpoint

```
GET /sitemap.xml
```

Returns a `application/xml` response conforming to the [Sitemaps Protocol 0.9](http://www.sitemaps.org/schemas/sitemap/0.9).
Responses are cached for **15 minutes** via `Cache-Control: public, max-age=900`.

## Content included

| Content type | Route | Priority | Change frequency |
|---|---|---|---|
| Home page | `/` | 1.0 | daily |
| Discover feed | `/discover` | 0.9 | hourly |
| Newsstand | `/newsstand` | 0.8 | daily |
| Follow Packs index | `/follow-packs` | 0.7 | weekly |
| About | `/about` | 0.6 | monthly |
| Pricing | `/pricing` | 0.6 | monthly |
| Terms of Service | `/tos` | 0.4 | monthly |
| Changelog | `/changelog` | 0.5 | weekly |
| Roadmap | `/roadmap` | 0.5 | weekly |
| Published articles (kind 30023) | `/p/{npub}/d/{slug}` | 0.7 | monthly |
| Magazines (kind 30040) | `/mag/{slug}` | 0.7 | weekly |

Articles are capped at the **1000 most recent** published entries to keep the sitemap response size manageable.
Only deduplicated magazine slugs (newest version per slug) are included.

## Crawler discovery

`/robots.txt` references the sitemap:

```
Sitemap: /sitemap.xml
```

`/sitemap.xml` is also listed in the `Allow` directives so crawlers that observe `Disallow: /` at the top can still fetch it.

## Footer link

A "Sitemap" link is included in the site footer for human discovery and to signal to crawlers via ordinary HTML linking.

## Implementation

- **Controller**: `src/Controller/SitemapController.php`
- **Template**: `templates/sitemap.xml.twig`
- **Translations**: `footer.sitemap` key in all 6 locale files

