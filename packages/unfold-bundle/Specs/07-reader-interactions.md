# Reader Interactions On Unfold

Status: delivered for public content in both bundled themes.

Implementation: bundle interaction contracts/policy/API, host local reader and
guarded refresh, atomic signed-event/outbox persistence, leased asynchronous
delivery and recovery, reader login continuation, and the shared AssetMapper
signing/UI bridge. Feature and deployment details are in
`../../../documentation/Unfold/reader-interactions.md`.

Local discussions have lossless 100-item pages, a 10,000-candidate safety ceiling
with explicit failure, and at most 40 historical content revisions. Counts are
distinct-reader local state, not statistics. Undo actions, gated interactions,
and docs-theme zap cosmetic work remain deferred.

This slice delivers **comments and replies, likes, and reposts** on public
publication content. It is not a statistics or owner-analytics slice.
Bookmarks and highlights remain later work, not prerequisites.

## Scope And Reader Journeys

Support all delivered public content kinds: articles (`30023`), chapters
(`30041`), wiki entries (`30818`), and community specifications (`30817`).
Both `default` and `docs` themes must offer the same interactions.

| Journey | Required behavior |
|---|---|
| Read a discussion | Show locally stored comments, replies, author previews, and existing zap activity without waiting for relay requests. Load further pages explicitly. |
| Comment | Log in if necessary, approve the signer for this origin, write a plaintext comment, sign, and see the locally accepted comment immediately. |
| Reply | Select a comment, see its author/parent preview, retain the publication content as the thread root, and cancel reply mode without losing the draft. |
| Like | Publish a positive reaction; show `Liked` and the reader's own state after acceptance and on reload. |
| Repost | Confirm sharing the public content, sign a generic repost, and show `Reposted` after acceptance and on reload. |
| Recover from failure | Preserve the draft and signed event; distinguish login/signing errors, local rejection, queued delivery, partial delivery, and failed delivery. Retry the same event. |

For the first release, likes are positive-only and reposts are unquoted.
Active buttons are not undo toggles: unlike, undo-repost, comment editing/deletion,
dislikes, emoji reactions, and quote reposts are deferred. Do not imply that a
second click retracts an immutable Nostr event.

Non-goals: visits/reach/engagement dashboards, analytics collection, owner/admin
workflow changes, bookmarks/highlights, notifications, full moderation tooling,
gated interactions, and payment/access redesign. Small action counts beside
reader controls are interaction state, not an analytics dashboard.

## Planning Baseline And Reuse Boundaries

This is not a greenfield comments implementation:

- `CommentProviderInterface` and `App\Unfold\CommentProviderAdapter` already read
  comments from the local `EventRepository`. The repository traverses replies.
- `ContextBuilder` already exposes comments, their count, and zap activity.
  Both post templates render a read-only, flat discussion.
- `CommentContentRenderer` already escapes plaintext and renders NIP-21 references.
  Preserve this renderer rather than accepting HTML/Markdown in comments.
- `PostData` already carries the full coordinate, content kind, tags, and event ID.
- That read-only rendering did not provide hosted composition, reply controls,
  own like/repost state, or generic repost publishing. This slice extends those
  foundations rather than duplicating them.

| Concern | Existing source | Planned reuse |
|---|---|---|
| Comment parsing and threading | `Nip22TagParser`, `EventRepository::findCommentsByCoordinate`, `CommentEventProjector`, `Comments` component | Reuse parsing, local projection, profile previews, and thread rules behind bundle DTOs; do not create a second protocol parser. |
| Comment hydration | `FetchCommentsMessage` / `FetchCommentsHandler`, `SocialEventService`, Redis comment payloads | Queue bounded refreshes; keep network work outside page and fragment reads. Filter replies, reactions, reposts, and zap activity into their correct UI sections. |
| Comment publishing | `CommentController`, `NostrEventVerifier`, `CommentEventProjector` | Extract/reuse verification and projection services, not the controller's synchronous relay-before-persist flow. |
| Like reads and publishing | `ReactionController`, `GenericEventProjector`, `PublishReactionMessage` / `PublishReactionHandler` | Reuse positive-like semantics, distinct-reader counts, local-first persistence, and async broadcast primitives. Generalize the target to all four kinds. |
| Reposts | `KindsEnum::GENERIC_REPOST`, `documentation/NIP/18.md`, generic event projection | Add the missing verified kind-16 write/read path; do not invent a kind-6 article repost or claim an existing repost composer. |
| Reader signing | `assets/controllers/nostr/signer_manager.js`, `utility/signer_modal_controller.js`, existing `nostr_unfold_*` controllers | Reuse `getSigner()`, `getPublicKey()`, `signEvent()`, and `connectBunker()`; adapt the bootstrap for reader pages, not owner-only administration. |
| Relay selection | `UserRelayListService`, `RelayRegistry`, `RelayPublishResult` | Reuse normalization, user relay lists, local relay integration, and actual acceptance classification. Do not hard-code another relay list. |

Do not blindly forward hosted writes to the main-domain controllers. Their
existing request validation, identity assumptions, synchronous comment broadcast,
and reaction-worker logging are not a complete hosted interaction contract.
In particular, a worker log saying all relays failed is not durable delivery
state or a successful publication.

## Identity And Target Resolution

- The publication remains the exact immutable root `30040:<owner>:<dtag>`.
  Resolve it from the registered host; never accept a client-selected replacement.
- Readers need authentication for writes, **not publication ownership**.
  Reuse the underlying identity normalization, not owner authorization.
- Before prepare, publish, retry, or interaction reads, verify that the target is
  a supported, public leaf in this publication's current content inventory.
  A valid arbitrary coordinate is not sufficient.
- Preserve the full `(kind, pubkey, identifier)` and significant identifier
  whitespace, Unicode, colons, and slashes. Do not use slug-only lookups.
- Comments/likes must keep working for content backed by existing Article
  projections rather than assuming every visible item has a separate Event row.
  Resolve the revision ID from local content storage when needed.
- Reposts require the actual original signed event, not reconstructed metadata
  or rendered HTML. Obtain it from local Article/Event storage where available;
  expose a clear unavailable state if the signed source cannot be obtained.
  Do not introduce a synchronous relay fallback during page loading.
- Content removed from the publication or marked with `s` scope tags is rejected,
  including a cached previously public version. A denial must not trigger a
  broader read fallback or include the content in error responses.
- A reply parent must belong to the verified thread rooted at this exact target.
  Reject conflicting roots, foreign parents, malformed ancestry, and cycles.
  Deleted/unavailable parents get a safe placeholder, not a cross-thread fetch.

Interactions belong to the content coordinate, not a publication-specific clone
of the event. A public leaf shared by two publications can share its discussion;
both hosts must still independently validate membership and access.

## Protocol Decisions

The checked-in [NIP-22](../../../documentation/NIP/22.md),
[NIP-25](../../../documentation/NIP/25.md), and
[NIP-18](../../../documentation/NIP/18.md) are the protocol references.
No new event kinds are needed.

### Comments And Replies: NIP-22, Kind 1111

- New comments use plaintext content and uppercase `A`, `K`, `P` tags for the
  addressable content root, its actual kind, and author.
- A top-level comment uses lowercase `a`, `e`, `k`, `p` for the same content
  coordinate, referenced revision ID, actual kind, and author.
- A reply retains the same uppercase root tags but uses lowercase `e`, `k=1111`,
  and `p` for the selected parent comment and its author. Do not replace the
  root with the parent comment ID.
- Reuse existing NIP-21 mention handling and applicable `q`/`p` tags. Do not
  misclassify quotes, reactions, reposts, or zap receipts as replies.
- Retain compatible existing event-ID-rooted threads through known local
  revisions and existing read rules; new writes use the stable coordinate root.
  Do not rewrite existing signed events.
- Present roots newest-first with a stable event-ID tie-break; replies are
  chronological within their thread. Paginate and bound traversal. Proposed
  initial limits: 25 roots per page, at most 100 comment items per response,
  and six levels of visible nesting with deeper replies accessible explicitly.

### Likes: NIP-25, Kind 7

- New likes have content `+`, `a` for the full coordinate, `e` for the exact
  referenced revision, `p` for its author, and `k` for its actual kind.
- Compatible existing positive reactions (`+` or empty content) remain readable.
  Counts are distinct reader pubkeys for the coordinate, not raw event totals.
- Preserve coordinate-level state across content revisions. Disable another
  creation while the action is pending or already active; event-ID replay and
  duplicate relay ingestion must not increase counts.
- No unlike UI in this slice. Later undo must use author-signed NIP-09 deletion
  requests and the existing deletion/tombstone infrastructure, not a local flag
  or a negative reaction masquerading as deletion.

### Reposts: NIP-18, Kind 16

- All four target kinds are non-kind-1 content, so use **generic repost kind 16**,
  not note-only kind 6.
- Include `e` for the original revision with a usable relay hint, `p` for the
  author, `k` for the original kind, and `a` for the full addressable coordinate.
- Normally embed the original signed event JSON in `content`, preserving its
  ID/signature and verifying it against the resolved public target. Never embed
  the rendered article or an invented unsigned event.
- For NIP-70-protected originals, keep repost content empty as specified by
  NIP-18; still retain the reference and relay hint.
- Show a confirmation explaining that reposts are public. Read own state and
  distinct-reposter counts locally; do not count kind-16 events as comments.
- Unknown original relay hints must not be fabricated. Missing valid source
  identity/hints produce an explicit unavailable state.

## Package Contracts, Routes, And Signing

Keep protocol policy, target resolution, controllers, DTOs, and theme behavior
in the package. Keep Doctrine, Redis, Messenger, relay gateway, and DN account
integration in host adapters; no `App\...` imports in bundle code.

Proposed narrow boundaries, finalized during the first implementation step:

- Reader identity/login continuation, separate from owner access. It can reuse
  the host identity helper without making reader actions owner-only.
- `InteractionReaderInterface`: local comment/thread and like/repost read models,
  composed with the existing comment provider and profile metadata contract.
  Extend the existing comment boundary deliberately for pagination; do not fork
  a competing comment retrieval implementation.
- `SignedInteractionPublisherInterface`: verified local acceptance and async
  delivery of an explicit comment/reply/like/repost intent, returning a typed
  result and durable event-specific delivery state.

Register a publication-scoped `/unfold/api/interactions` route collection before
the hosted catch-all. Provide public paginated reads, a separate private own-state
read, prepare/publish operations, and a bounded delivery-status/retry operation.
The coordinate is a query/body value, not a path segment that loses identifier
characters. Public post routes stay unchanged; no new owner-admin mount is needed.

- Anonymous readers can read; create actions prompt login and preserve the
  current canonical content URL and draft. Allow only recognized content paths
  on registered publication hosts as login return targets.
- Follow D18: DN authenticated sessions on publication subdomains. Verify the
  configured cookie behavior; localhost and unavailable sessions need a clear
  login state, not an owner-admin redirect.
- NIP-07 permission is per-origin. Reuse the existing signer selection flow and
  explain approval for this publication. NIP-46 credentials/connections must
  restore or reconnect through that flow; do not assume origin-local browser
  storage is shared because the DN session cookie is shared.
- The browser signs as the reader. The server verifies the hash/signature and
  requires the signed pubkey to match the authenticated reader. DN must never
  sign reader comments, likes, or reposts with an operator/server key.
- Protect writes with CSRF, same-origin checks, request limits, and rate limits.
  Reject unsupported kinds, conflicting root/parent tags, target/author/revision
  mismatches, oversized comments, and invalid timestamps before projection.
  Use the existing 1,000,000-byte signed-write request ceiling; bound plaintext
  comments separately (proposed 4,000 UTF-8 bytes).
- If a prepared revision is no longer valid, return an explicit stale-target
  response and refresh the target; never silently mutate an already signed event.
- Keep JS/CSS in asset files, not templates. Reuse shared signer helpers through
  the host AssetMapper bootstrap; do not embed the whole main-site layout or
  copy independent signer implementations into both themes.
- The Handlebars post templates do not inherit the main Twig layout. Wire a
  minimal reader entrypoint, the required importmap/Stimulus registration, and
  the shared signer-modal markup in both themes. Data attributes alone do not
  initialize controllers. Preserve the existing `nostr-tools`, `nostr-tools/nip46`,
  and `nostr-tools/utils` imports and the signer manager's session restore/sync
  flow; do not create another bunker-session store.

## Local Acceptance, Relay Delivery, And Caching

1. Validate identity, publication membership, public access, action tags, source
   revision, and signature. Store the signed event locally using existing
   projectors with event-ID deduplication.
2. Commit local storage and a durable delivery job/outbox atomically, or provide
   equivalent recovery if enqueue fails after commit. Do not lose a locally
   accepted action merely because a relay is offline.
3. Broadcast asynchronously. Preserve existing reader/target-author relay
   selection and the local relay path. Any extra publication relay must come
   from existing configuration, be deduplicated/capped, and accept public events;
   do not assume a future paid-content home relay already exists.
4. Record queued, accepted, partial, or failed delivery per event using actual
   relay `OK` results. Retry transient failures with bounds; exhausted failures
   remain visible. `Queued` must never be labelled `Published`.
5. Replay the same signed event ID on transport retries. Duplicate POSTs, queue
   retries, and relay ingestion do not create another row or UI item. Persist
   pending payloads before sending, scoped to origin/account/target/action.
6. After local acceptance, show the new local item/state and refresh the affected
   interaction payload, not all publication content/page caches. Preserve drafts
   on pre-commit failures and preserve event-specific retry state after commit.

Public content HTML must not cache a reader's pubkey, CSRF token, own-action flags,
draft, or delivery status. Load private state separately with `private, no-store`.
Public interaction caches include publication root and full target coordinate;
pagination also includes the cursor. Content revision/membership changes must
invalidate or recheck access rather than exposing stale removed/scoped content.

Page loads and interaction reads are local-first with **zero synchronous relay
calls**. Stale/missing interaction data queues deduplicated hydration through
existing workers. Relay/profile refresh failures do not turn a local read into
an empty successful discussion. The current `ContextBuilder` catch-all that
silently returns `[]` must be replaced on the changed path with logged,
distinguishable unavailable state.

## Implementation Order And Delegation

1. **Shared foundation:** settle the narrow DTOs/contracts, local target and thread
   checks, hosted route ordering, reader identity/login continuation, signer
   bootstrap, and durable delivery/result semantics. Add negative-path fixtures
   for all four kinds before exposing controls.
2. **Comments and replies:** first complete vertical slice in both themes.
   Reuse the existing discussion data, parent previews, safe renderer, projector,
   and hydration. Deliver composing, replying, local visibility, paging, and
   queued/failure/retry UX together.
3. **Likes:** reuse reaction services after extracting/generalizing what is
   actually shared. Deliver positive creation, distinct-reader count, own state,
   reload behavior, and duplicate prevention across all four kinds.
4. **Reposts:** add the kind-16 service and source-event validation, then
   confirmation, own state, delivery/retry behavior, and both-theme presentation.
5. **Integrated handoff:** verify the same identity/target/delivery behavior in
   both themes and the main DN reader; update the existing feature documentation
   and add one changelog item per delivered feature/fix, not for this plan.

Delegate host adapters/projectors/queue work to one agent and bundle
controllers/themes to another **after** the foundation contract is agreed.
Give them disjoint files and one shared fixture/event schema. The supervising
agent owns wiring, protocol review, cross-kind/theme acceptance, and regression
checks; do not let each agent invent a signer or publication identity model.

## Acceptance And Targeted Coverage

- Every journey works on all four kinds in both themes, including Article-backed
  content, significant identifiers, missing metadata, and empty discussions.
- With the gateway unavailable, existing local discussions still load promptly;
  page/fragment requests make no synchronous relay calls. Own actions survive
  reload with explicit queued/failed delivery rather than disappearing.
- Top-level/reply fixtures assert exact NIP-22 root/parent tags. Foreign parents,
  conflicting roots, cycles, missing parents, and duplicate events are covered.
- Like/repost fixtures assert kind/coordinate/revision/author tags, unique-reader
  state/counts, proper original JSON, protected repost behavior, and replay safety.
- Hosted routes are not swallowed by the catch-all. Anonymous reads work;
  anonymous writes, signer/session mismatch, CSRF failures, unregistered hosts,
  foreign publication targets, and scoped/removed content writes are denied.
- Persistence failure, enqueue failure, all-relay rejection, partial acceptance,
  worker exhaustion, lost HTTP responses, and same-event retries have distinct
  tested outcomes. Existing deletion/tombstone guards must still apply.
- Public caches never contain private state; two readers and two publications
  cannot inherit each other's state. A shared content coordinate still has one
  coherent discussion.
- Manual interaction checks cover signer cancel/reconnect, draft preservation,
  reply cancel, keyboard focus, mobile layout, screen-reader status, and both
  themes. All new user-facing strings use the five existing translation locales.
- Use existing PHPUnit/protocol fixtures and AssetMapper checks inside Docker,
  targeting the changed services/controllers/themes. No new test/build tooling,
  statistics pipeline, Xdebug, or breakpoints are required.

## Docs-Theme Zap Dialog Styling (Delivered)

User report: **the docs-theme zap dialog works, but its visual styling is
incomplete/not polished**. This cosmetic styling follow-up is now delivered;
it does not change the invoice/payment feature. The docs post continues to
load `docs/assets/zap.css` and the shared `default/assets/zap.js`.

When addressing it, review all dynamically generated dialog states: amount
selection, split recipients, QR/invoice display, copy/open-wallet controls,
loading, validation/error, success, close/focus states, and narrow screens.
Spacing, typography, controls, overflow, contrast, focus indication, and
narrow-screen layout now follow the docs theme rather than copying the default
theme's shadows and rounded corners. The external stylesheet covers every
generated dialog state while preserving zap and split-payment behavior.

## Deferred Bookmarks, Highlights, And Gated Rules

- Bookmarks remain standard reader-signed `10003` list updates on the reader's
  relays, using the existing bookmark UI and warn-before-overwrite/latest-list
  flow. They are not included in the next slice.
- Highlights remain reader-signed `9802` with coordinate/author references and
  quoted text; reuse `HighlightService`, `HighlightRepository`,
  `RefreshArticleHighlightsMessage`, and the existing selection UI later.
- Gated highlights must carry source `s` tags and go through Spec 06's central
  home-relay-only publishing guard. Authorization applies to every DB/relay/cache
  read, not just initial page access.
- Gated comments and especially embedded reposts can leak protected text.
  Public coordinate references also reveal interest. Their disclosure/routing
  policy requires a separate decision with Spec 06; this slice must reject them,
  not silently choose public fan-out. The previous draft's permissive gated-like
  and bookmark stance is not authorization to implement that behavior now.
