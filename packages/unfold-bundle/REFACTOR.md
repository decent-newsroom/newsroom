# Unfold Refactor Intent

An Unfold is permanently identified by exactly one root magazine coordinate.
Preserve that identity and its descendant index structure; do not add a multi-root
abstraction or allow an existing Unfold to be retargeted to another root.

## Current Refactor Slice

Decouple setup and maintenance from AppData entirely:

- Align these docs with event-independent setup.
- Share setup between operator administration and subscription activation.
- Persist local publication settings by root coordinate, initially the existing
  theme selection. Keep subdomain mapping and subscription billing separate.
- Preserve existing sites, routes, default settings, and the functioning content
  rendering path. Keep legacy AppData reads only as compatibility support.
- Do not fetch, sign, or publish `30078` to create, activate, edit, or render a site.

Index events remain authoritative for publication content and navigation; local
settings own local choices. Future referenced events will own their own contents,
while local settings select the references. No speculative access-service fields
are needed in the first slice.

## Subsequent Publication Work

- One owner administration surface contains the getting-started wizard, settings,
  analytics, index/content management, and publication-scoped article editor.
- The same administration mounts on the subdomain at `/admin` and on the main
  domain at `/mag/{mag}/admin`, including publications without hosting. Access is
  scoped to the owner in the root coordinate; see `Specs/08-unified-publication-admin.md`.
- Both publication-first and subdomain-first onboarding remain goals. A reserved
  subdomain is a hosting draft until attached to a root coordinate.
- Each publication has predictable RSS and sitemap URLs (already delivered).
  Owner footer links remain future work.
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

A portable definition is still wanted. Design a custom event only after scoped
access establishes which relationships define an Unfold. Derive it from the
relevant publication, audience, payment, and service configuration; do not freeze
a replacement AppData schema now. Exclude credentials, receipts, tokens, and DN
billing state. Export/import must remain independent of ordinary saves.

Internal extraction into `decent-newsroom/unfold-bundle` is already done.
Preserve its bundle contracts and host adapters. Independent distribution and a
standalone installation with its own database and relay remain future work.

`Specs/00-refactor-plan.md` sequences the work. Specifications describe intended
behavior unless explicitly marked delivered.
