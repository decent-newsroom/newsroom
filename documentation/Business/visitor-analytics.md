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

## Excluded routes

Generic visitor analytics now exclude all requests under `/api/*`.

That exclusion is applied at query time:

1. **At capture time** — `/api/*` requests are still persisted as `Visit` records for targeted endpoint analytics.
2. **At query time** — generic visitor analytics ignore `/api/*` rows so they do not affect page-traffic metrics.

This keeps visitor counts focused on navigational page traffic instead of internal or utility endpoints.

## Affected files

- `src/EventListener/VisitTrackingListener.php`
- `src/Repository/VisitRepository.php`
- `src/Controller/Administration/VisitorAnalyticsController.php`
- `templates/admin/analytics.html.twig`

## Notes

- Existing utility metrics that rely on the `visit` table, including article publish activity and zap invoice generation tracking, continue to work.
- Because `/api/*` rows are excluded from generic visitor analytics queries, API traffic is not included in visit totals, route tables, recent visits, or unique-visitor calculations.


