# Unfold Publication Administration Specs

Status: staged implementation specifications. Internal Composer extraction,
publication discovery, shared local setup, and persistent theme settings are
delivered. Owner administration now delivers overview/settings through both
mounts. The publication footer and owner links are delivered. Wizard migration,
analytics, content configuration, audience/payment
workflows, and gated access remain planned.

An Unfold is permanently identified by exactly one immutable root magazine
coordinate. Setting it up or changing local settings does not require any
application-definition event. Definition-event design will be respecified only
after gated access is complete.

These package-local specs travel with the implementation. Spec 06 records the
event kinds and cross-service access contract; spec 08 records the delivered dual
admin mounts and ownership rules alongside the deferred consolidation work.

## Spec Map

| File | Purpose |
| --- | --- |
| `00-refactor-plan.md` | Master plan, implementation stages, decision log, and risks. |
| `01-owner-admin.md` | Delivered local setup, theme settings, and owner administration foundation. |
| `02-audiences-and-payment-targets.md` | Planned audience and payment events with local reference selections. |
| `03-feeds-sitemap-footer.md` | Delivered discovery endpoints and two-level publication footer; conditional future links remain planned. |
| `04-owner-dashboard-and-content-management.md` | Delivered overview/theme settings and planned analytics/content pages. |
| `05-tests-and-rollout.md` | Setup coverage, compatibility, migration requirements, and later rollout. |
| `06-gated-access-and-payments.md` | Gated-access contract for kinds `38133`/`30879`/`8879`/`28877`/`28878`. |
| `07-reader-interactions.md` | Likes, bookmarks, highlights, and gated interaction rules. |
| `08-unified-publication-admin.md` | Delivered mounts/context/ownership and planned host-admin consolidation. |

## Terms And Authority

- Unfold publication: one root `30040:<owner_pubkey>:<dtag>` coordinate and its
  descendant content; a subdomain is optional hosting, not its identity.
- Publication owner: the pubkey in that immutable root coordinate.
- Local publication settings: host-persisted choices keyed by the full coordinate.
  Only the theme is implemented in the current slice.
- `UnfoldSite`: host subdomain mapping, separate from publication settings and
  subscription billing.
- Referenced events: authoritative for their own contents, such as root index
  metadata or future audience/payment details. Local selections identify which
  references this publication uses.
- Audience: planned `30879` publication-scoped access offer.
- Publication payment targets: planned addressable `38133` event, separate from
  the owner's personal `10133` targets.
- Publication definition: deferred for respecification after gated access is
  complete; no event kind, schema, or compatibility workflow is planned now.

## Rollout

1. **Delivered:** shared operator/subscription setup and persistent local settings.
2. **Delivered:** publication context, both owner admin mounts, overview, and theme settings.
3. Consolidate content management/editor workflows.
4. Add audience/payment events and selected references; optionally ship Audience
   Preview as **Gated access coming soon**, without checkout or entitlements.
5. Establish scope-aware publishing and authorization across all reads/caches,
   then integrate the external bridge, mint, and relay.
6. After gated access is complete, respecify publication-definition events.

Existing coordinate-only mappings remain valid and use default settings.
