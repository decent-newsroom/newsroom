# Unfold Refactor Intent

An Unfold is permanently identified by exactly one root magazine coordinate.
Preserve that identity and its descendant index structure; do not add a multi-root
abstraction or allow an existing Unfold to be retargeted to another root.

## Delivered Refactor Slices

- Shared operator/subscription setup and persistent local theme settings keyed by
  the immutable root coordinate are delivered. Hosting and billing stay separate.
- Owner administration now provides an overview and theme settings through both
  `<subdomain>/admin` and `/mag/{mag}/admin`, including publications without hosting.
- Bundle-owned publication context and identity contracts enforce owner access;
  platform administrator privileges do not bypass publication ownership.
- Both mounts share settings and validation. Existing sites retain their routes
  and default-theme behavior when no local settings exist.

Index events remain authoritative for publication content and navigation; local
settings own local choices. Future referenced events will own their own contents,
while local settings select the references. No speculative access-service fields
are needed in the delivered setup and owner-settings slices.

## Subsequent Publication Work

- Extend the delivered overview/settings administration with the getting-started
  wizard, analytics, index/content management, and publication-scoped article editor.
- The same administration mounts on the subdomain at `/admin` and on the main
  domain at `/mag/{mag}/admin`, including publications without hosting. Access is
  scoped to the owner in the root coordinate; see `Specs/08-unified-publication-admin.md`.
- Both publication-first and subdomain-first onboarding remain goals. A reserved
  subdomain is a hosting draft until attached to a root coordinate.
- Each publication has predictable RSS and sitemap URLs (already delivered).
  The two-level default footer and locally configured owner links are delivered.
- Owners can configure payment targets and scope/audience definitions through the
  relevant signed events, with their selections saved locally.
- Reader interactions include likes (`7`), bookmarks, and highlights (`9802`).
  Quoted gated content must follow the same access rules as its source.

## Scoped Access

The access chain is payment targets → payment bridge → mint → gated relay:

- Payment targets say where the owner receives payments.
- Audiences describe the access offer and supply the coordinate used by content
  scope tags.
- The bridge processes payment and sends signed receipts directly to the mint.
- The mint issues short-lived authorizations based on valid receipts.
- The relay checks the authenticated subscriber and authorization scope before
  returning gated events.

Expected kinds and agreed cross-service behavior remain in
`Specs/06-gated-access-and-payments.md`: `38133` payment targets, `30879`
audiences, `8879` attestations, `28877` holder assertions, and `28878`
access authorizations. Older conflicting proposals remain superseded.

The bridge and mint live in separate repositories; relay implementation is
external. DN operates the first bridge/mint and offers
`premium.decentnewsroom.com` as the initial home relay. An active DN subdomain
subscription enables access-service eligibility, not ordinary local setup.

Before scoped publishing ships, enforce home-relay-only routing centrally and
authorize every content read, including database/graph fallbacks and caches.
Public metadata, discovery documents, and quoted interactions must not leak
gated content. Missing external integrations must not prevent publication setup.

## Portability And Packaging

Publication-definition event design is deferred and will be respecified only
after gated access is complete. There is no definition-event implementation or
compatibility work in the active refactor slices.

Internal extraction into `decent-newsroom/unfold-bundle` is already done.
Preserve its bundle contracts and host adapters. Independent distribution and a
standalone installation with its own database and relay remain future work.

`Specs/00-refactor-plan.md` sequences the work. Specifications describe intended
behavior unless explicitly marked delivered.
