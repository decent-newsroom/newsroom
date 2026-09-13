# Unfold Publication Administration Specs

Status: staged implementation specifications. Internal Composer extraction and
publication discovery routes are delivered. Local setup and persistent theme
settings form the current slice. Owner administration, audience/payment workflows,
scoped access, and portable event export remain planned.

An Unfold is permanently identified by exactly one immutable root magazine
coordinate. Setting it up or changing local settings does not require any
application-definition event. Legacy AppData support is compatibility-only.

These package-local specs travel with the implementation. Spec 06 records the
event kinds and cross-service access contract; spec 08 defines the future dual
admin mounts and ownership rules. Spec 01 replaces the former AppData-first model.

## Spec Map

| File | Purpose |
| --- | --- |
| `00-refactor-plan.md` | Master plan, implementation stages, decision log, and risks. |
| `01-appdata-and-owner-admin.md` | Local setup/settings and planned owner administration; filename retained for existing references. |
| `02-audiences-and-payment-targets.md` | Planned audience and payment events with local reference selections. |
| `03-feeds-sitemap-footer.md` | Delivered discovery endpoints and planned publication footer. |
| `04-owner-dashboard-and-content-management.md` | Planned settings, analytics, and content management pages. |
| `05-tests-and-rollout.md` | Setup coverage, compatibility, migration requirements, and later rollout. |
| `06-gated-access-and-payments.md` | Gated-access contract for kinds `38133`/`30879`/`8879`/`28877`/`28878`. |
| `07-reader-interactions.md` | Likes, bookmarks, highlights, and gated interaction rules. |
| `08-unified-publication-admin.md` | Planned admin mounts, publication context, ownership, and host-admin consolidation. |

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
- Portable definition: future custom event derived from the working scoped-access
  model. No kind or schema is allocated yet, and publishing it will not block
  setup, settings saves, or operation.

## Rollout

1. Share operator/subscription setup and persist local settings without AppData.
2. Add the publication context, both admin mounts, and owner checks.
3. Complete footer settings and consolidate content management/editor workflows.
4. Add audience/payment events and selected references; optionally ship Audience
   Preview as **Gated access coming soon**, without checkout or entitlements.
5. Establish scope-aware publishing and authorization across all reads/caches,
   then integrate the external bridge, mint, and relay.
6. Derive the portable event and prove reconstruction on a clean host.

Existing coordinate-only mappings remain valid and use default settings. Missing
AppData never creates a setup-required state or restricts owner administration.
