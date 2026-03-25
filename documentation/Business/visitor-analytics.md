# Visitor Analytics

## Overview

The visitor analytics page at `/admin/analytics` tracks page-level visit activity for admins.

This update expands the analytics to make use of the stored HTTP referer data and tightens the definition of a visit so that utility endpoints are not counted as page traffic in the generic visitor metrics.

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

The admin analytics page now includes:

- visit counts with a non-empty referer header for the last 24 hours
- visit counts with a non-empty referer header for the last 7 days
- all-time count of visits that arrived with a referer
- a top-referrers table for the last 30 days
- referer values in the recent visits table

Referrers are grouped by the stored referer string exactly as received from the request header.

### API utility analytics

API requests are still recorded in the `visit` table.

That allows endpoint-specific analytics, such as article publish activity from `/api/article/publish`, to continue working even though those requests are excluded from generic visitor totals.

### Subdomain analytics

When a visit lands on an Unfold subdomain (e.g. `support.decentnewsroom.com`), the `VisitTrackingListener` reads the `_unfold_subdomain` request attribute — already set by `UnfoldRequestListener` (priority 32, runs before the visit listener at priority 0) — and stores the subdomain name on the `Visit` entity.

The admin analytics page includes a dedicated **Subdomain Analytics** section with:

- total subdomain visit counts (24h / 7d / all time)
- unique subdomain visitors (last 7 days)
- visits broken down by subdomain (last 30 days)
- subdomain visits per day chart (last 30 days)
- top subdomain routes table (last 7 days)
- recent subdomain visits table

Subdomain metrics are separate from (and additive to) the main-domain analytics. Visits with `subdomain IS NULL` are main-domain traffic; visits with a non-null subdomain are Unfold traffic.

## Excluded routes

Generic visitor analytics now exclude all requests under `/api/*`.

That exclusion is applied at query time:

1. **At capture time** — `/api/*` requests are still persisted as `Visit` records for targeted endpoint analytics.
2. **At query time** — generic visitor analytics ignore `/api/*` rows so they do not affect page-traffic metrics.

This keeps visitor counts focused on navigational page traffic instead of internal or utility endpoints.

## Affected files

- `src/Entity/Visit.php`
- `src/EventListener/VisitTrackingListener.php`
- `src/Repository/VisitRepository.php`
- `src/Controller/Administration/VisitorAnalyticsController.php`
- `templates/admin/analytics.html.twig`
- `migrations/Version20260325120000.php`

## Notes

- Existing utility metrics that rely on the `visit` table, including article publish activity and zap invoice generation tracking, continue to work.
- Because `/api/*` rows are excluded from generic visitor analytics queries, API traffic is not included in visit totals, route tables, recent visits, or unique-visitor calculations.


