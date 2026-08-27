# Unfold Refactor — Master Plan

Status: planning. Source of intent: `src/UnfoldBundle/REFACTOR.md` (the wishlist).

This document sequences the Unfold refactor into shippable phases, records the
decisions already made, and lists the open questions that block later phases.
Specs `01`–`05` remain valid for structure and flows; **their kind numbers are
superseded** by `06-gated-access-and-payments.md` (see Decision Log).

## Big Picture

Unfold becomes a self-contained publication platform:

- Public: subdomain website + RSS + sitemap per publication.
- Owner: dashboard on the subdomain with wizard, analytics, editorial links.
- Money: payment targets → payment bridge → mint → gated relay access chain.
- Endgame: bundle extracted from this repo, runnable on any sovereign domain
  with its own DB and relay.

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

### Phase 3 — Owner dashboard shell, wizard, analytics (Spec 04)

- `/admin/*` on the subdomain, owner-pubkey access rule (Spec 01).
- Getting-started wizard: claim/confirm subdomain → pick/create publication →
  sign AppData → optional theme/about → done. Covers both onboarding orders.
- Link to the live subdomain site with `target="_blank"`.
- Visitor analytics from the existing `Visit` table, filtered strictly to the
  current subdomain.
- Subscription analytics cards render a not-connected null state until
  Phase 6.

### Phase 4 — Payment targets and audiences (Spec 02 structure, Spec 06 kinds)

- Publication payment targets: `kind:38133` (addressable NIP-A3 `payto`).
- Audiences / scope definitions: `kind:30879`.
- Dashboard pages to create/edit/sign both; AppData references updated after
  publish.
- Reuse `PaymentTargetService` parsing where possible; it currently handles
  personal `kind:10133` — extend, don't fork.

### Phase 5 — Gated publishing path

- Scope/audience tag on articles (`30023`) and indexes (`30040`/`30041`)
  marks content as gated.
- **Hard relay-routing guard**: any event carrying a scope tag is published
  *only* to the publication home relay. This must be enforced centrally in
  the publish path, not per-controller — the existing publish flows fan out
  to all user write relays and would leak gated content across Nostr.
- Requires an active DN subdomain subscription (existing
  `PublicationSubdomainSubscription`) to enable gating for a publication.

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

## Open Questions (blocking later phases)

| # | Question | Blocks |
|---|---|---|
| Q1 | ~~`30879` semantics vs NIP-99.~~ **Resolved → D7**: new kind, NIP-99 tag vocabulary only. | — |
| Q2 | Token transport: a Nostr REQ cannot natively carry an event. How does the client present `28878` to the relay — NIP-42-style AUTH extension, a custom `["AUTH-TOKEN", <event>]` message, or a tag-based filter convention? Must be agreed with the relay implementer. | Phase 6 |
| Q3 | Scope tag name and format on gated events: earlier drafts used `["scope", <coordinate>]` or `["G", "a:..."]` (deleted NIP-SB). Spec 06 proposes a single answer — confirm with relay implementer (single-letter tags are relay-indexable). | Phase 5 |
| Q4 | `8879` attestation is a regular (stored) kind linking a pubkey to a purchased scope. Where may it be stored? Proposal: bridge→mint direct delivery only, never broadcast (privacy). | Phase 6 |
| Q5 | Does the relay keep any publisher-write authorization (old `18101`), or is write access out of scope for the new model? | Phase 6 |
| Q6 | `UnfoldSite` vs `PublicationSubdomainSubscription`: merge or keep joined by subdomain? Proposal: keep separate (claim/config vs billing), add explicit FK. | Phase 1 |
| Q7 | Subdomain auth: are DN session cookies valid on `*.<base-domain>`? If not — widen cookie scope or add a subdomain login flow. NIP-07 signer approval is per-origin either way. | Phase 2b, 3 |

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

## Test Strategy (summary — details per spec)

- Unit: AppData/audience/payment-target parsers, relay-routing guard.
- Functional: owner access rules, feeds/sitemap responses.
- Gherkin (`tests/NIPs/`): AppData linkage spec (Spec 05) + gated access
  chain spec once Q1–Q4 are settled.
- Commands: `docker compose exec php bin/phpunit` targeted per phase.
