# Owner Dashboard And Content Management

> **Mounts:** `08-unified-publication-admin.md` is authoritative for where
> these pages mount and how the publication is resolved. Read "on their own
> subdomain" below as "in a resolved publication context", which may be a
> subdomain or a `/mag/{mag}/admin` coordinate mount. Paths below are relative
> to `PublicationContext.adminPathPrefix`.

Status: owner overview, theme settings, footer links, About/category references,
and category content assignment are delivered on both mounts. Hosted reading
supports articles, chapters, wiki entries, and community-authored specifications.
Analytics, root metadata editing, audiences, payment targets, and the expanded
dashboard remain planned. Operator setup stays a separate host surface.

## Goal

Give each Unfold owner a publication-scoped admin area on their own subdomain. The admin should manage publication configuration, content organization, payment targets, audiences, and analytics without granting DN platform-admin access.

## Admin Navigation

Delivered pages:

- `/admin`: dashboard overview.
- `/admin/settings`: local publication settings.
- `/admin/content`: About/category-reference inventory and category content
  assignment.

Planned pages:

- `/admin/index`: magazine index editing.
- `/admin/audiences`: audience tier management.
- `/admin/payment-targets`: publication payment descriptor management.
- `/admin/analytics`: visitor analytics and future subscription analytics.

Every page must use the owner access rule from `01-owner-admin.md`.

## Dashboard

The delivered overview shows publication identity, theme, hosting status, and
public/discovery links when hosting exists. Content-management links and additional
readiness states below accompany their future workflows.

Full dashboard target:

- Publication title, optional subdomain, immutable root coordinate, and owner pubkey.
- Saved local settings and the relevant publication event metadata.
- Quick links to RSS, sitemap, public site, and content management.
- Separate readiness states for publication configuration, hosting, and access
  integration. Missing audiences/payment services do not block ordinary management.

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

- List existing categories from the publication index and their ordered contents.
- Accept pasted coordinates/naddrs for existing content of kinds `30023`,
  `30041`, `30818`, and `30817`; a searchable picker remains deferred.
- Let owners add/remove content references in categories they author and that
  the root directly references. Foreign-owner category contents are read-only.
- Publish the updated category `kind:30040` event through the owner signer.
- Preserve the root and local settings. Reject stale category revisions and
  retry relay publication using the same signed event.

Hosted public reading:

- Author-qualified paths are `/{npub}/a/{dtag}`, `/{npub}/chapter/{dtag}`,
  `/{npub}/wiki/{dtag}`, and `/{npub}/spec/{dtag}`.
- Resolve full coordinates within the publication, not a slug alone.
- Render `30023` and `30817` as Markdown, `30041` and `30818` as AsciiDoc.
- Community-authored NIPs are distinguished from official NIPs and retain
  authorship; wiki entries follow NIP-54.
- Existing unique `/a/{slug}` article URLs remain usable. Multi-kind public
  pages, lists, RSS, and sitemap share the same canonical URL rules.
- Scoped content is not enabled by assignment and is excluded from public
  output; this is not an entitlement or gated-publishing implementation.

Magazine index editing:

- Edit publication title, summary/description, logo/image, and ordered category coordinates.
- Publish the updated root publication index `kind:30040` through the owner signer.
- Keep the entire root coordinate immutable; editing the root index publishes a
  new revision at the same coordinate.

Payment target setup:

- Edit publication-level `38133` payment target rows.
- Publish `38133` through owner signer.
- Save the selected payment-target coordinate locally after publish.

Audience setup:

- Edit `30879` audience title, summary, prices, duration, image, and optional payment targets.
- Publish `30879` through owner signer.
- Save selected audience coordinates locally after publish.
- Until the access chain is connected, display these offers as **Gated access
  coming soon** and do not show checkout, entitlement, subscriber, or revenue
  data.

## Failure States

- Missing signer: event publishing shows connection guidance and does not submit
  unsigned events; local settings remain editable without signing.
- Signed event pubkey mismatch: reject server-side.
- Publication coordinate owner mismatch: reject server-side.
- Relay publish partial failure: persist only after local validation; show relay results and allow retry.
- Cache stale after local settings save or event publish: invalidate the affected
  SiteConfig, category, home posts, feed, and sitemap caches for the publication.