# Owner Dashboard And Content Management

> **Mounts:** `08-unified-publication-admin.md` is authoritative for where
> these pages mount and how the publication is resolved. Read "on their own
> subdomain" below as "in a resolved publication context", which may be a
> subdomain or a `/mag/{mag}/admin` coordinate mount. Paths below are relative
> to `PublicationContext.adminPathPrefix`.

## Goal

Give each Unfold owner a publication-scoped admin area on their own subdomain. The admin should manage publication configuration, content organization, payment targets, audiences, and analytics without granting DN platform-admin access.

## Admin Navigation

Initial pages:

- `/admin`: dashboard overview.
- `/admin/appdata`: AppData signing and publication settings.
- `/admin/content`: article/category management.
- `/admin/index`: magazine index editing.
- `/admin/audiences`: audience tier management.
- `/admin/payment-targets`: publication payment descriptor management.
- `/admin/analytics`: visitor analytics and future subscription analytics.

Every page must use the owner access rule from `01-appdata-and-owner-admin.md`.

## Dashboard

Show:

- Publication title, subdomain, root coordinate, owner pubkey, and AppData status.
- Latest signed AppData metadata.
- Quick links to RSS, sitemap, public site, and content management.
- Setup warnings when AppData, home relay, payment targets, or audiences are missing.

## Visitor Analytics

Use the existing `Visit` table, which already stores Unfold subdomain visits.

Owner analytics are filtered to the current `UnfoldSite.subdomain` only:

- Visits last 24 hours and 7 days.
- Unique visitors last 7 days.
- Top routes.
- Visits per day.
- Recent visits with route and referer.

Owners must not see other subdomains or main-domain analytics.

## Subscription Analytics

First implementation may render provider-backed cards with a null state:

- Payments processed by DN via payment bridge.
- Current subscribers from the configured mint.
- Active subscribers per audience.
- Revenue totals by currency.

Until the payment bridge and mint integrations are available, cards show a clear not-connected state and no fabricated counts.

## Content Management

Simple article/category assignment:

- List existing categories from the publication index.
- List known article coordinates already present in the graph/database.
- Let owner add article coordinates to a category.
- Let owner remove article coordinates from a category.
- Publish the updated category `kind:30040` event through the owner signer.

Magazine index editing:

- Edit publication title, summary/description, logo/image, and ordered category coordinates.
- Publish the updated root publication index `kind:30040` through the owner signer.
- Keep the `UnfoldSite.coordinate` stable unless the owner intentionally changes the root publication.

Payment target setup:

- Edit publication-level `38133` payment target rows.
- Publish `38133` through owner signer.
- Update AppData `payment_targets` reference after publish.

Audience setup:

- Edit `30879` audience title, summary, prices, duration, image, and optional payment targets.
- Publish `30879` through owner signer.
- Update AppData repeated `audience` references after publish.
- Until the access chain is connected, display these offers as **Gated access
  coming soon** and do not show checkout, entitlement, subscriber, or revenue
  data.

## Failure States

- Missing signer: show connection guidance and do not submit unsigned events.
- Signed event pubkey mismatch: reject server-side.
- Publication coordinate owner mismatch: reject server-side.
- Relay publish partial failure: persist only after local validation; show relay results and allow retry.
- Cache stale after publish: invalidate AppData, SiteConfig, category, home posts, feed, and sitemap caches for the site.