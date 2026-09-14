# Visitor Analytics

## Overview

The visitor analytics page at `/admin/analytics` tracks page-level visit activity for admins.

Both `/admin` and `/admin/analytics` show the same lightweight snapshot, cached for 60 seconds. The query first selects at most the latest 100,000 recorded requests using the visit primary-key index, then keeps those from the last 24 hours and applies the page-traffic filters. Limiting the source rows before filtering and aggregation bounds the work even when traffic is mostly bots or API calls, and needs no new database index or migration.

The snapshot shows page views, distinct non-null visitor/session IDs, visits with a referer, and the five most visited routes. Counts describe only this sample; they are not extrapolated totals. A notice appears when the 100,000-record cap is reached. The same route path on different subdomains is combined, as in the existing generic analytics. Empty data is shown as zero, while a failed query or cache read shows an unavailable message.

The dashboard no longer performs corpus-wide article deduplication, database/user totals, all-time bounce-rate queries, or live relay diagnostics. Admin tool links remain available. The analytics overview no longer runs long-range charts, referrer rankings, publish/zap totals, or bot summaries. Existing detail, bot, and subdomain reports are still separate opt-in pages and can still be expensive on a large database.

The dashboard Refresh action invalidates the shared snapshot for both pages.

## What is tracked

### Page visits

Visitor tracking is handled by `VisitTrackingListener` (`src/EventListener/VisitTrackingListener.php`).

For each tracked main request, the application stores:

- the request path (`route`)
- the visitor identifier (`sessionId` field, used for both authenticated sessions and anonymous cookie-based continuity)
- the visit timestamp
- the HTTP `Referer` header when present
- the subdomain name when the request is for an Unfold subdomain site (null for main domain requests)

### Referrer analytics

The overview counts page visits with a non-null, non-empty referer in the bounded sample. Full referer ranking methods remain available in `VisitRepository`, but are not called by the overview.

### API utility analytics

API requests are still recorded in the `visit` table.

That allows endpoint-specific analytics, such as article publish activity from `/api/article/publish`, to continue working even though those requests are excluded from generic visitor totals.

### Subdomain analytics

When a visit lands on an Unfold subdomain (e.g. `support.decentnewsroom.com`), the `VisitTrackingListener` reads the `_unfold_subdomain` request attribute — already set by `UnfoldRequestListener` (priority 32, runs before the visit listener at priority 0) — and stores the subdomain name on the `Visit` entity.

The separate `/admin/analytics/subdomains` page includes:

- total subdomain visit counts (24h / 7d / all time)
- unique subdomain visitors (last 7 days)
- visits broken down by subdomain (last 30 days)
- subdomain visits per day chart (last 30 days)
- top subdomain routes table (last 7 days)
- recent subdomain visits table

Subdomain metrics are separate from (and additive to) the main-domain analytics. Visits with `subdomain IS NULL` are main-domain traffic; visits with a non-null subdomain are Unfold traffic.

## Excluded routes

### API routes

Generic visitor analytics exclude all requests under `/api/*`.

That exclusion is applied at query time:

1. **At capture time** — `/api/*` requests are still persisted as `Visit` records for targeted endpoint analytics.
2. **At query time** — generic visitor analytics ignore `/api/*` rows so they do not affect page-traffic metrics.

### Asset routes

All asset-serving routes are excluded at **capture time** — they are never persisted as `Visit` records. This covers:

- **Main-domain prefixes:** `/assets/`, `/icons/`, `/fonts/`, `/themes/`, `/favicon.ico`
- **Unfold bundle prefixes:** `/unfold-themes/`
- **Static file extensions:** `.ico`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.webp`, `.avif`, `.css`, `.js`, `.map`, `.woff`, `.woff2`, `.ttf`, `.eot`, `.xml`, `.json`, `.webmanifest`
- **Service workers and manifests:** `/service-worker.js`, `/chat-sw.js`, `/manifest.webmanifest`

As a safety net, the same asset prefixes are also excluded at **query time** in `VisitRepository`, so any asset visits that were recorded before this change do not affect visitor metrics.

This keeps visitor counts focused on navigational page traffic instead of internal, utility, or static asset endpoints.

### Preview/partial routes

Editor preview routes serve HTML fragments (partials), not full pages. They are excluded at **capture time** — never persisted — and also at **query time** as a safety net for any previously recorded rows:

- `/editor/markdown/preview` — legacy Markdown preview panel exclusion kept for historical rows and compatibility
- `/article-editor/preview/` — article editor live preview

## Affected files

- `src/Entity/Visit.php`
- `src/EventListener/VisitTrackingListener.php`
- `src/Repository/VisitRepository.php`
- `src/Controller/Administration/VisitorAnalyticsController.php`
- `templates/admin/analytics.html.twig`
- `migrations/Version20260325120000.php`

## Notes

- API utility requests continue to be stored, and the repository retains publish/zap query methods. The lightweight overview does not execute those methods.
- Because `/api/*` rows are excluded from generic visitor analytics queries, API traffic is not included in visit totals, route tables, recent visits, or unique-visitor calculations.
