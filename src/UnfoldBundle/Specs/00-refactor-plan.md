# Unfold Refactor — Master Plan

Status: planning. Source of intent: `src/UnfoldBundle/REFACTOR.md` (the wishlist).

This document sequences the Unfold refactor into shippable phases, records the
decisions already made, and lists the open questions that block later phases.
Specs `01`–`05` remain valid for structure and flows; **their kind numbers are
superseded** by `06-gated-access-and-payments.md` (see Decision Log).

## Big Picture

Unfold becomes a self-contained publication platform:

- Public: subdomain website + RSS + sitemap per publication.
- Owner: one publication admin — wizard, analytics, content management and the
  article editor — reachable both on the subdomain (`/admin`) and on the main
  domain by coordinate (`/mag/{mag}/admin`), so a subdomain is a presentation
  feature rather than an administration prerequisite (Spec 08).
- Money: payment targets → payment bridge → mint → gated relay access chain.
- Endgame: bundle extracted from this repo, runnable on any sovereign domain
  with its own DB and relay.

The product bias is **collections, not single articles**: the host app's
magazine administration folds into the bundle, and single-article authoring
survives only as a tool that the publication admin can borrow (D11).

## Roles And External Components

| Component | Operated by | Repo | Responsibility |
|---|---|---|---|
| Unfold bundle | DN (this repo) | here | Rendering, dashboard, feeds, event authoring |
| Payment bridge | DN | separate repo (owner) | Shows payment targets, success hooks, signs `kind:8879` attestations |
| Mint | DN | separate repo (owner) | Verifies attestations, issues `kind:28878` access authorizations |
| Gated relay | third party | external | Stores scoped events, validates `28878` tokens on REQ |
| Home relay | DN (`premium.decentnewsroom.com`) | — | Single option for v1 |

The bundle must treat bridge/mint/relay as **external contracts** (spec 06),
never as in-process code — this is also what makes extraction possible.

## Phases

Ordered so each phase ships value on its own and later phases depend only on
earlier ones. Specs referenced in parentheses.

### Phase 0 — Documentation and kind alignment (this changeset)

- Master plan (this file) + gated-access contract spec (`06`).
- Supersession notice on Spec `02`; superseded drafts deleted per D2
  (`documentation/Subscriptions/`, `documentation/Business/Subscriptions/`,
  `documentation/Business/Submissions/`, `documentation/NIP/SB.md`);
  `notifications-pro.md` rescued to `documentation/notifications-pro.md`
  (live paid feature, unrelated to the superseded model).
- Later, with first code: add `KindsEnum` cases `38133`, `30879`, `8879`,
  `28877`, `28878`.

### Phase 1 — Ownership and AppData (Spec 01, Spec 05 migrations)

- `UnfoldSite` gains `ownerPubkey` + `appDataCoordinate`; backfill from
  coordinate; migration + diagnostic for malformed rows.
- Owner-signed NIP-78 `kind:30078` AppData (browser signer, never DN-signed).
- AppData parser/builder in bundle config layer; DB-first, relay fallback.
- Both onboarding orders work: publication-first and subdomain-first
  (wishlist line: "create a publication first or claim a subdomain first").
  Subdomain-first creates an `UnfoldSite` shell without a coordinate; the
  wizard (Phase 3) completes it.

### Phase 2 — Feeds, sitemap, robots, footer (Spec 03)

Independent of everything else — ship early.

- `/rss.xml`, `/feed.xml`, `/{category}/rss.xml`, `/sitemap.xml`,
  `/robots.txt` registered **before** `RouteMatcher` static-file rejection.
- Reuse `PostData`/`ContentProvider`; publication-local absolute URLs only.
- Note: existing `RssFeedService` is *ingestion* (external feeds in), and the
  existing sitemap generator is main-domain only — both are not reusable
  as-is; new bundle-local generation code.

### Phase 2b — Reader interactions on Unfold (Spec 07)

- Likes (`kind:7`), bookmarks (`kind:10003`), highlights view + create
  (`kind:9802`) on publication pages.
- Heavy reuse of host-app code (`ReactionController`, `HighlightService`,
  bookmark/highlight Stimulus controllers) behind bundle interfaces.
- Public-content interactions can ship right after Phase 2; **gated**-content
  interactions (highlight scope tagging) depend on the Phase 5 chokepoint.
- Requires subdomain auth/signing decision (Q7).

### Phase 3 — Unified publication admin: mounts, shell, wizard, analytics (Spec 08, Spec 04)

- `PublicationContext` + two resolvers (host, coordinate); every admin
  controller depends on the context only (Spec 08).
- Route work: split the bundle route collections so admin can serve apex-domain
  routes, and order both admin collections **before** the `unfold_site`
  `/{path}` catch-all.
- Owner-pubkey access rule (Spec 01); coordinate mount scopes lookups by
  `(dtag, currentUserPubkey)` so ownership is structural (Spec 08).
- Getting-started wizard: pick/create publication → sign AppData →
  optional theme/about → optional subdomain → done. Covers both onboarding
  orders; subdomain step is no longer terminal.
- Wizard draft moves from `mag_wizard` session state to Redis keyed by
  coordinate, so drafts survive both mounts.
- Link to the live subdomain site with `target="_blank"` when one exists.
- Visitor analytics from the existing `Visit` table, filtered strictly to the
  current subdomain; null state on the coordinate mount.
- Subscription analytics cards render a not-connected null state until
  Phase 6.

### Phase 3b — Admin consolidation and editor integration (Spec 08)

- Migrate `MagazineWizardController` and `MagazineEditorController` into the
  bundle admin, re-scoped from `ROLE_ADMIN`/slug to owner/coordinate.
- Keep platform moderation and billing on the main domain under `ROLE_ADMIN`
  (`MagazineAdminController`, `PublicationSubdomainAdminController`,
  `UnfoldSiteController` repair screens).
- Publication-scoped article editor: reuse `EditorController` behind a thin
  wrapper that injects `PublicationContext`; publish `30023` then append the
  coordinate to a category and publish `30040`, with an append-retry path.
- `/article-editor/*` stays as-is for context-free single-article authoring.

### Phase 4 — Payment targets and audiences (Spec 02 structure, Spec 06 kinds)

- Publication payment targets: `kind:38133` (addressable NIP-A3 `payto`).
- Audiences / scope definitions: `kind:30879`.
- Dashboard pages to create/edit/sign both; AppData references updated after
  publish.
- Reuse `PaymentTargetService` parsing where possible; it currently handles
  personal `kind:10133` — extend, don't fork.

### Phase 4a — Audience Preview

Ship the audience foundation before payment, mint, and relay integrations exist:

- Eligible publication owners can publish `38133` payment targets and `30879`
  audience definitions, linked from AppData.
- Publication pages render audience cards as **Gated access coming soon**, with
  their title, description, price, and duration. An optional notify-me or
  interest action may be added, but no checkout or entitlement claim is shown.
- Dashboard subscription analytics remain explicitly not connected and show no
  fabricated subscriber or revenue counts.
- Audience definitions are real, public publication configuration; they do not
  make any article exclusive and they must not cause an `s` tag to be published.

This milestone creates visible progress and lets owners prepare their offers
without promising access that the external services cannot yet enforce.

### Phase 5 — Gated publishing path

- Scope/audience tag on articles (`30023`) and indexes (`30040`/`30041`)
  marks content as gated.
- **Hard relay-routing guard**: any event carrying a scope tag is published
  *only* to the publication home relay. This must be enforced centrally in
  the publish path, not per-controller — the existing publish flows fan out
  to all user write relays and would leak gated content across Nostr.
- Requires an active DN subdomain subscription (existing
  `PublicationSubdomainSubscription`) to enable gating for a publication.
- Do not enable this phase until Q3 is agreed with the relay implementer and
  the centralized guard has unit and Gherkin coverage. A faux paywall or a
  scoped event sent to ordinary write relays would leak paid content.

### Phase 6 — Access chain integration (Spec 06)

Depends on external repos (bridge, mint) and third-party relay work.

- Subscriber flow: pick audience → bridge payment → `8879` attestation →
  `28877` holder assertion to mint → `28878` authorization → REQ to gated
  relay with token.
- Client-side: token acquisition/refresh, attaching tokens to gated REQs.
- Blocked on the open protocol questions in spec 06 (esp. token transport).
- Write the as-implemented protocol NIP at `documentation/NIP/` (replacing
  the deleted draft NIP-SB) once the contract has survived integration.

### Phase 7 — Extraction readiness

- Audit bundle dependencies on host app (`UnfoldSite`, `Visit`,
  `PaymentTargetService`, Redis cache, `NostrClient`); introduce bundle-owned
  interfaces with host-app adapters.
- Then follow the `extract-symfony-bundle-to-package` skill.
- Not a blocker for any earlier phase, but every phase should avoid *new*
  hard couplings to host-app internals.

## Decision Log

| # | Decision | Rationale |
|---|---|---|
| D1 | Wishlist kinds win: `30879` (audience) replaces `38110`; `38133` (publication payment targets) replaces the provisional `30133`. | REFACTOR.md declares prior proposals superseded. |
| D2 | Superseded docs are **deleted**, not kept with banners: `documentation/Subscriptions/`, `documentation/Business/Subscriptions/`, `documentation/Business/Submissions/`, `documentation/NIP/SB.md`. Design history stays in git; a new NIP doc describing the *actually implemented* gated-access protocol replaces NIP-SB along the way (Phase 6 deliverable). | Owner decision (2026-08); dead drafts were generating confusion, and spec 06 is now the single forward-looking contract. |
| D3 | Bridge+mint+token model replaces the SB relay-issued-grant model (`8110`/`8102`/`8112`/`8103`/`8113`, publish grants `18101`/`8101`). | Relay stays a dumb token validator; payment verification concentrates in bridge+mint, which live in separate repos anyway. |
| D4 | AppData (owner-signed `30078`) is the authoritative publication definition; `UnfoldSite` (DB) stores the subdomain claim, owner pubkey, and *references* to signed events, plus DN-only operational fields (home relay assignment, mint/bridge endpoints). | The wishlist says the subdomain record "contains the whole definition", but a DN-local DB row can't travel to a sovereign domain — signed events can. DB = claim + cache + DN ops; events = definition. |
| D5 | v1 home relay is fixed to `premium.decentnewsroom.com`; mint and bridge are DN-operated. | Wishlist. Single option keeps AppData `home_relay` optional in v1. |
| D6 | Cap and dedupe everything derived from relays (feed size 50, etc.). | Matches Spec 03 and repo-wide guardrails. |
| D7 | `30879` is a new kind, not NIP-99 `30402`. Digital access resources have no `location`/`g` and never reach `status: sold`; NIP-99 has live marketplace implementations (Shopstr, Plebeian Market, Amethyst) that would mis-render audience offers as listings. Reuse only the NIP-99 tag vocabulary (`title`/`summary`/`image`/`published_at`/`price` array). Verified `30879`, `38133`, `8879`, `28877`, `28878` unallocated in the upstream NIPs kind table (2026-08); register in `nostr-protocol/registry-of-kinds` when stable. | Spec 06. |
| D8 | The publication admin has **two mounts** — `<sub>/admin` and `<base>/mag/{mag}/admin` — sharing one implementation via a `PublicationContext` resolver. A subdomain is not required to administer a publication. | Owner decision (2026-09). Magazines without a subdomain need the same administration; the coordinate is the real identity, the subdomain is presentation. Also creates the host-agnostic seam Phase 7 extraction needs anyway. |
| D9 | The coordinate mount resolves publications by `(dtag, currentUserPubkey)`, never by slug alone, and 404s instead of falling back to another pubkey's event. | `MagazineStructureService::findLatestIndexBySlug()` filters only on kind and d-tag, so slug resolution is last-writer-wins across pubkeys. Safe for public reading, disqualifying as an ownership signal. Scoping by the authenticated pubkey makes ownership structural rather than a comparison. |
| D10 | Host-app magazine administration folds into the bundle (wizard, index editing, content assignment), scoped to the publication owner. Platform moderation and billing stay on the main domain under `ROLE_ADMIN`. | Owner decision (2026-09): one administration surface, not two. Preserves the distinction between owning a publication and operating the platform. |
| D11 | The standalone `/article-editor/*` surface is kept; the publication admin reuses the same editor with a `PublicationContext` injected. | Owner decision (2026-09). Absorbing the editor entirely would block context-free authoring; duplicating it would fork the publish path. Coupling stays in a thin wrapper so the standalone editor keeps zero collection dependencies. |
| D12 | Wizard draft state moves from the `mag_wizard` session key to Redis keyed by coordinate. | A session-scoped draft cannot cross the two mounts, and the single session key forbids more than one draft per user. Also removes Q7 from the admin path. |
| D13 | The relay authorizes a gated WebSocket subscription when the client sends `["AUTHZ", "<subscription-id>", <kind:28878 event>]` before its matching `REQ`. | The authorization is explicitly bound to one subscription and follows the NIP-42 authentication model without altering REQ filter semantics. |
| D14 | Gated event scopes use repeatable `["s", "30879:<owner-pubkey>:<audience-dtag>"]` tags. | Single-letter tags are relay-indexable and provide one canonical scope representation. |
| D15 | The payment bridge delivers `kind:8879` attestations directly to the mint over HTTPS/API. | Attestations stay out of relay storage; the bridge may separately return a receipt to the subscriber. |
| D16 | Gated relay publisher-write authorization is out of scope for v1. | A publication-owner-signed event routed through the centralized home-relay-only guard may be written without a separate token. |
| D17 | `UnfoldSite` and `PublicationSubdomainSubscription` remain separate and are linked by an explicit foreign key. | Separates publication claim/configuration from platform billing while providing a reliable relationship. |
| D18 | DN session cookies are scoped to the base domain for subdomain reader interactions. | This preserves the existing authenticated experience across publication subdomains; signer approval remains per-origin. |
| D19 | Magazine projection identity must move from globally unique slug to publication coordinate in a separate follow-up before public publication features expand. | The current global slug uniqueness cannot represent colliding d-tags from different owners. |
| D20 | Root publication d-tags are immutable after creation. | `/mag/{dtag}/admin` remains stable without introducing a separate coordinate URL encoding. |

## Open Questions (blocking later phases)

| # | Question | Blocks |
|---|---|---|
| Q1 | ~~`30879` semantics vs NIP-99.~~ **Resolved → D7**: new kind, NIP-99 tag vocabulary only. | — |
| Q2 | ~~Token transport.~~ **Resolved → D13**: clients send `["AUTHZ", "<subscription-id>", <kind:28878 event>]` before the matching `REQ`. | — |
| Q3 | ~~Scope tag name and format.~~ **Resolved → D14**: repeatable `s` tags contain the `30879` audience coordinate. | — |
| Q4 | ~~`8879` attestation delivery.~~ **Resolved → D15**: bridge-to-mint HTTPS/API only; never broadcast. | — |
| Q5 | ~~Publisher-write authorization.~~ **Resolved → D16**: out of scope for v1. | — |
| Q6 | ~~`UnfoldSite` and subscription relationship.~~ **Resolved → D17**: separate entities with an explicit FK. | — |
| Q7 | ~~Subdomain authenticated sessions.~~ **Resolved → D18**: scope the DN session cookie to the base domain. NIP-07 approval remains per-origin. | — |
| Q8 | ~~Magazine projection identity.~~ **Resolved → D19**: make coordinate the projection identity in a separate follow-up before public publication features expand. | — |
| Q9 | ~~Stable coordinate-mount URL after d-tag rename.~~ **Resolved → D20**: root publication d-tags are immutable. | — |

## Known Issues / Risks

1. **Kind renumbering fallout** — three doc sets used the old numbers; grep
   for `38110|30133` before writing code and never add those to `KindsEnum`.
2. **Ephemeral kinds** (`28877`, `28878` in 20000–29999): relays don't store
   them — intentional (short-lived), but means the mint must be online for
   every token issuance/refresh; no offline replay.
3. **Gated-content leakage** is the highest-severity product risk: one missed
   publish path that fans out to public relays defeats the whole payment
   model. This includes **highlights of gated articles**, which quote paid
   text (Spec 07). The Phase 5 guard needs a unit-tested central chokepoint
   plus a Gherkin spec.
4. **External dependency risk**: Phase 6 cannot be tested end-to-end until
   bridge, mint, and relay exist. Spec 06 is the contract to hand to those
   implementers *now*; build DN-side against fakes.
5. **Docs drift** (acknowledged in the wishlist): the former
   `documentation/Subscriptions/`, `Business/Subscriptions/`,
   `Business/Submissions/`, and `NIP/SB.md` described a ReWire grant system
   that was never fully built — all deleted in Phase 0 (D2;
   `notifications-pro.md` rescued). The implemented protocol gets a fresh
   NIP doc in Phase 6.
6. **Slug-based ownership** is the highest-severity risk in the admin work.
   `findLatestIndexBySlug()` has no pubkey filter, so any pubkey can publish a
   `kind:30040` with an existing d-tag and win the lookup. D9 removes this from
   the admin path by scoping to the authenticated pubkey; the risk returns the
   moment any admin code path resolves a publication from a slug alone. Needs a
   dedicated security test.
7. **Route ordering regression**: the `unfold_site` catch-all is `/{path}` with
   `path: '.*'`. Any admin route registered after it silently becomes a
   category lookup and 404s. Cheap to break, cheap to test — cover it
   functionally.
8. **Admin migration scope creep**: moving the wizard and index editor from
   `ROLE_ADMIN`/slug to owner/coordinate touches the publish path that already
   works. Migrate behind the new mounts before removing the old routes, so the
   two can be compared on the same data.

## Test Strategy (summary — details per spec)

- Unit: AppData/audience/payment-target parsers, relay-routing guard.
- Functional: owner access rules, feeds/sitemap responses.
- Gherkin (`tests/NIPs/`): AppData linkage spec (Spec 05) + gated access
  chain spec once Q1–Q4 are settled.
- Commands: `docker compose exec php bin/phpunit` targeted per phase.
