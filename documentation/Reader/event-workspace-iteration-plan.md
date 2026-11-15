# Event Workspace and Composable Previews

Status: **Steps 1–4 are complete for the bounded implementation delivered so
far.** Completion means the current supported surfaces and graph slices are
implemented and covered by their targeted tests; it does not mean every
capability in the longer-term workspace proposal is implemented. Treat any
defect found in later or broader testing as a bug to fix, not as a reason to
reopen these milestone statuses. Browser/live worker/Redis verification and
deployment-runtime acceptance remain follow-up validation, not claims made by
this status.

The signed-event presentation and reference snapshot, typed source
records, and resolution-result contracts are implemented. Five bounded Step 2
slices add Reading Nook kind `39089` member expansion, kind `30040` nested
index expansion, kind `10003`/`30003` bookmark reference expansion, and Bookshelf
kind `30045` My Books directory reference expansion, plus My Content article
and draft previews with ordered references. The
proposed contact-list (kind `3`) preview is intentionally skipped. A bounded
shared DB-only target preview resolver is shipped for bookmarks and the
Bookshelf directory. General resolution, other expandable sources, and
selectable viewers remain proposed. A bounded Step 3 subset now provides
user-triggered asynchronous discovery for Reading Nook TOC public publication
addresses and separately authorized lazy previews for available kind `30041`
TOC children.
Step 4 has an additive exact-event-ID (`e`) parsed-reference slice: lowercase
event references are stored separately from coordinates with original tag
positions, duplicates, and NIP-10 marker/relay context. A migration and all
graph ingestion, rebuild, backfill, and audit writers carry the exact IDs.
An additional bounded slice indexes lowercase profile (`p`) occurrences with
source-kind-aware roles. These bounded reference-indexing slices complete the
current Step 4 scope; general target resolution and graph traversal remain
future extensions.

### Delivered bounded Step 3 subset: Reading Nook TOC discovery

- Reading Nook root and nested kind `30040` TOCs expose a separate discovery
  button for valid unresolved, unpinned `a` occurrences targeting public
  `30040`/`30041` kinds only. Other kinds, `e` IDs, malformed pins, and cold
  exact pins cannot queue work. A pin already available locally resolves
  exactly; a cold pin is explicitly unavailable rather than fetched and
  accidentally replaced by the latest revision. Ordinary rendering remains
  DB-only. Bookshelf TOCs do not acquire this action.
- The authenticated GET/POST occurrence endpoint derives identity exclusively
  from the authorized exact source's signed raw tag position on the current
  page. POST requires a session CSRF token. Every POST and poll rereads the owned
  root and all bounded exact nested sources, verifies raw parent relationships
  and signed traversal state, and rechecks author, strict reader mute, hidden
  coordinate, and tombstone policy. Source ownership is not a browser grant.
- `PublicationTocDiscovery` atomically reserves Redis keys: one pending job per
  coordinate for 60 seconds, two attempts per reader/target per ten minutes,
  and four new jobs per reader per minute. Redis failures fail closed.
  Duplicate occurrences share discovery work, but keep distinct DOM rows.
- `FetchEventFromRelaysMessage`/Handler have an additive public-address-only
  workspace mode. Only four configured content relays are supplied; signed and
  browser relay hints are ignored. No author-relay discovery, Books API fallback,
  Article projection, body conversion, media fetch, or Mercure topic is used.
  Empty valid `d` identifiers work. Exact pins do not enter this unpinned
  discovery path. Exact-pinned indexes and chapters resolve by event ID when
  already local; missing pins remain explicit and are never fetched or upgraded
  to the current coordinate revision.
- The Stimulus controller performs no work on connect. Each click makes one
  POST then at most 20 GET checks at three-second intervals, with ten-second
  HTTP timeouts and a shared four-active-occurrence browser bound. Completion
  replaces only that occurrence's inner panel; siblings and their disclosures
  remain mounted. Focus is restored without scrolling; no page/frame reload.
  Disconnect aborts requests/timers and releases the browser slot.
- Polls resolve only the requested occurrence locally and apply existing public
  target visibility policy. `30040` keeps cycle/depth-safe nested disclosure;
  available `30041` targets expose bounded metadata and a separate lazy chapter
  preview. The TOC fragment itself never includes a chapter body. Worker
  completion is not an authorization result. Missing/failed transport,
  persistence, or relay results converge to a bounded generic failure with
  explicit retry; server cooldown/attempt limits remain authoritative. Failed
  queue dispatch releases only its own atomic Redis reservation and counters.
- Known limits: discovery checks configured relays, not arbitrary author/hint
  relays; late jobs require another explicit check after the polling window.
  Cold coordinate tombstones conservatively prevent discovery even if a newer
  allowed revision might exist. A cold exact pin is unavailable because the
  current Event store retains only the winning replaceable revision; fetching
  it through the generic projector could substitute latest. No worker error
  details or private identifiers are broadcast. This is not shared graph
  recursion or all of Step 3.
- Discovery-only validation at that milestone: isolated Docker checks passed:
  **160 PHPUnit tests / 1,141
  assertions** across TOC page, discovery, fetch handler, discovery controller,
  Twig, TOC controller, Bookshelf, and expansion rollback suites, plus **five
  Node tests** for the Stimulus controller. All six locale YAML files parse and
  contain the same eight discovery keys. At that earlier checkpoint the
  Compose PHP service was unavailable; the isolated PHP image was 8.5.6, not
  the deployment's required patched runtime. Full-app Twig/container lint and
  AssetMapper compile were blocked by the missing installed
  `DecentNewsroom\UnfoldBundle\UnfoldBundle` class. These checks do not verify
  browser/live worker/Redis integration.

### Delivered bounded Step 3 subset: Reading Nook chapter previews

- Available kind `30041` child occurrences in root and nested Reading Nook
  publication-index TOCs have their own lazy Turbo Frame. Exact local pins can
  preview directly; an unpinned reference becomes previewable only after the
  existing explicit discovery action reports a local target.
- Each fragment requires `ROLE_USER`, re-reads the exact owned root and every
  bounded nested-index source in the traversal path, and re-derives the
  selected child only from its source tag position. Nested signatures bind the
  exact source, page, occurrence, ancestry, and coordinate; they are integrity
  state, not authorization. A separate preview signature binds the displayed
  local chapter ID/coordinate to that source occurrence, so a hand-built URL
  cannot bypass explicit discovery for an unpinned reference.
- Only a matching kind `30041` coordinate and exact pin are accepted. Target
  hidden-coordinate, strict reader-mute, author-suppression, and NIP-09
  tombstone policy is reapplied before returning body or metadata. Missing,
  changed, and blocked targets fail closed. Responses are private and
  `no-store`.
- The fragment exposes only tag-derived title/summary, identity metadata, and
  the requested chapter body. A leaf AsciiDoc conversion prevents recursive
  embed previews; conversion failure retains metadata and renders escaped
  plain text. Sensitive tag evidence withholds title/summary and is identified
  after the explicit lazy disclosure is opened.
- Ordinary TOC rendering remains DB-only and does not resolve unpinned
  chapters. This slice does not mount Bookshelf, invoke Article projections,
  perform network discovery, or expand references from chapter bodies.
- Known limits: no browser/worker/Redis integration claim is made for this
  slice; existing cold-reference discovery limits remain unchanged. Other
  Reading Nook sources, Bookshelf, and generic chapter expansion are out of
  scope.
- Validation: focused Docker PHPUnit coverage passes **127 tests / 818
  assertions** across chapter preview service/controller/Twig, TOC page and
  discovery, expansion rollback, and disclosure regression tests. Translation
  YAML and both changed Twig templates pass their Symfony lint commands.
  Browser, worker, and Redis integration checks remain pending.

Bookshelf containing-index links also compose the existing public-index preview
and TOC primitives at an exact-ID Bookshelf route, with independently authorized
public sources/fragments (not the owner-only Reading Nook endpoints). See
[Book detail navigation](bookshelf-local-fallback.md#book-detail-navigation)
for the bounded local-only behavior; generic magazine routing is unchanged.

### Shipped bounded shared target preview slice

- Both bookmark (`10003`/`30003`) and directory (`30045`) reference pages now use
  `TargetPreviewResolver` and `event_workspace/_target_preview.html.twig`.
  Only the visible 50 source occurrences enter resolution. Source ordering,
  duplicates, malformed rows, labels, identities, pagination, actions, owner
  policy, and the existing expansion rollback flag are unchanged.
- One bounded Event query resolves exact `e` IDs, latest unpinned `a` coordinates
  (newest timestamp, lowest event ID on ties), and exact directory `a[3]` pins.
  A mismatched or absent pin never falls back to latest. Bookmark fourth slots
  are not pins. No current-record graph row or Article projection is required.
- Target metadata is allowed only for public kinds `30040`, `30041`, `1`, `20`,
  `21`, and `22`. Author suppression, NIP-09 tombstones, hidden coordinates, and
  authenticated viewer strict mutes are batch-checked on every fragment.
  Drafts/private/unknown kinds are restricted. Kind `30023` is deliberately
  restricted too: Event-only records do not safely establish Article/member
  entitlement. Broader article authorization is pending, not bypassed.
- Existing identities use `EventWorkspaceResolutionResult`. Missing, restricted,
  invalid-pin, and mismatched-pin states expose no target presentation.
  Malformed raw references retain their existing invalid labels; `p`/`t`
  references remain reference-only without profile lookup.
- Returned available snapshots contain only identity/kind/time and a bounded
  tag-derived title; sensitive titles are withheld. No target body or signature
  column is selected, and snapshots expose no raw tags, outgoing references,
  descriptions, images, or body text. The escaped shared partial is non-clickable
  and never loads media. Strict mute or DB failures fail the request closed.
- No recursive expansion, network/relay/API lookup, persistence, async work,
  body conversion, or changes to the normal Bookshelf resolution pipeline.

### Shared Step 2 disclosure shell and rollback

- `Molecules:LazyDisclosure` provides the native closed `details`/`summary`,
  lazy Turbo Frame, and exact-URL fallback link. Existing wrappers retain their
  labels, CSS classes, attributes, and frame IDs; My Content and nested TOCs use
  the same shell. Expansion stays separate from Open/Manage/Edit/delete actions.
- `EVENT_WORKSPACE_EXPANSION_ENABLED` defaults to **true**, preserving shipped
  behavior. Symfony's `event_workspace_expansion_enabled` parameter supplies both
  the Twig gate and fragment controllers. Set it to `false` and rebuild/clear the
  deployed Symfony cache as usual to roll back: all workspace expansion controls
  disappear and all fragment actions (including discovery, signed nested TOCs and every
  pagination request) return 404 before lookup, validation, or rendering.
  Existing authentication requirements remain in place. Ordinary inventory,
  read, edit, publish, and removal routes are not gated.
- Reading Nook mounts the existing Bookshelf directory disclosure once, outside
  inventory rows and action menus, using the unchanged owner-only endpoint and
  `bookshelf-directory-references` frame ID. It adds no inventory copy, count,
  eager query, network call, or resolver. My Books also mounts it once on its
  separate page, so IDs remain unique within each page.
- Step 3+ general shared resolution, selectable Book/Magazine/profile viewers, generic
  recursive `a`/`e` expansion, browser concurrency controls, and broader
  Event-only inventories remain intentionally deferred. Existing bounded local
  TOC traversal is unchanged; this shell does not implement a general browser.
  Bookshelf freshness and containing-index lookup are unchanged.

### Shipped Step 2 subset: My Content article previews

- Article and draft rows retain their existing title/editor, public view,
  coordinate-copy, and signed-delete actions, with a separate native lazy
  preview disclosure for rows with an exact event ID.
- Each fragment queries the Article by exact event ID and authenticated owner,
  rechecks article kind, author suppression, hidden coordinate, and NIP-09
  deletion, and returns private/no-store content. Drafts never become public.
- A matching local Event supplies the presentation; a complete matching raw
  Article event is the fallback. Without either, the disclosure explicitly
  reports the original as unavailable, without fabricating tags from projections.
- Shared tag-based previews do not depend on processed HTML. Outgoing references
  retain signed order, duplicates, invalid occurrences, and source positions,
  in 50-reference pages pinned to the same event. Sensitive text and references
  remain gated. Targets are not looked up, fetched, or recursively expanded.
- Inventory querying, filtering, counts, sorting, and 25-row pagination remain
  unchanged. Broader Event-only inventory and database-bounded filtering are
  separate follow-up work.

### Shipped Step 2 subset: Bookshelf directory references

- My Books retains the bundle's cards, opening/removal actions, and existing
  resolution pipeline, with a separate lazy, reference-only directory disclosure.
- The initial fragment selects the latest local owned kind `30045` revision for
  `my-book-collection`; later pages are pinned to its exact event ID.
- Up to 50 `Content`-role `a`/`e` occurrences are displayed from shared
  presentations over original signed tags, not normalized editable tags or
  deduplicated Books API results. Order, positions, duplicates, malformed entries,
  coordinates, exact IDs, and NKBIP-04 version pins are retained.
- Every fragment checks authenticated ownership, kind, signed directory
  identifier, author suppression, hidden coordinate, and NIP-09 deletion;
  responses are private and `no-store`. No local directory means an empty state,
  not a fabricated event.
- Visible targets now receive the bounded DB-only metadata previews described
  above; no API/relay call, body rendering, persistence, or recursive expansion
  is performed by this disclosure.

### Shipped Step 2 subset: Reading Nook bookmarks

- Owned kind `10003` bookmark lists and kind `30003` bookmark sets have a
  separate lazy disclosure, paginated at 50 occurrences.
- `e`, `a`, and `p` references are interpreted using the shared event
  presentation contract; `t` topics are read from their original ordered tags.
  Source positions, order, duplicates, and malformed occurrences are retained.
- Exact event IDs and profiles are shown as `note`/`npub`; parameterized
  addressable-event references use `naddr` when supported. Topics remain
  hashtags. Visible `a`/`e` targets now receive bounded DB-only metadata previews;
  profiles are not looked up and no targets are fetched from the network.
- Every fragment requires `ROLE_USER`, re-reads the exact source event for the
  current owner's pubkey, accepts only kinds `10003` and `30003`, and checks
  author suppression and NIP-09 deletion. Responses are private and `no-store`.
- The reference list now includes the shared non-clickable target metadata
  preview where authorized. It does not expose bookmark event bodies or add
  kind `3` follow-list previews.

### Shipped Step 2 subset: Reading Nook publication-index TOC references

- An eligible owned kind `30040` row has a separate native disclosure and lazy Turbo
  Frame at `GET /reading-nook/publication-indexes/{eventId}/toc`. It is pinned
  to the exact owned source event ID.
- Each request requires `ROLE_USER`, re-queries that ID for the current owner's
  pubkey and kind `30040`, and checks author suppression and NIP-09 deletion.
  Successful responses are private and `no-store`.
- Pages display at most 50 `Content`-role `a` reference occurrences from
  `EventPresentationFactory`, retaining source positions, order, duplicates,
  invalid occurrences, coordinates, and version pins. Pagination bounds output;
  the source event's tags are still scanned in memory. A single batched local
  query resolves visible nested kind `30040` targets.
- Unpinned addresses use the current local revision, matched by kind, pubkey,
  and raw `d`-tag identity. A valid version pin fetches its exact event ID and
  validates the complete coordinate; a missing or mismatched pin never falls
  back to latest. Exact `e` references are not structural index references and
  are not resolved here.
- Child authors need not match the inventory owner. A child is eligible for
  metadata only after the canonical `30040:pubkey:d-tag` coordinate is not
  hidden by `HiddenCoordinateRepository`, its author is not suppressed by
  `ContentAuthorAccessPolicy`, its exact event/revision is not NIP-09
  tombstoned, and its author is not in the current reader's NIP-51 mute list.
  Hidden-coordinate, author-suppression, and deletion checks are batched per
  displayed page; the current reader's local mute list is loaded once per
  request. A policy lookup failure denies the fragment rather than treating an
  unknown result as permission. A missing local mute-list event means the
  reader has no locally known `p` mutes.
- Kind `30040` is a public index under NKBIP-01. The nested target is read as
  that exact public `Event`; no Article projection, draft/private Article
  policy, or Essayist membership is used. NIP-70's protected-event marker is
  not by itself a read-denial predicate for this public index kind. Root TOC
  access still requires the owner's exact source revision and existing
  suppression/deletion guards. Nested fragments require `ROLE_USER`, a valid
  HMAC traversal signature keyed by the framework `kernel.secret`, an exact
  locally stored kind `30040` event whose
  full coordinate matches the signed state, and independent hidden-coordinate,
  reader-mute, author-suppression, and NIP-09 deletion checks. Signatures bind
  event ID, ancestor path, occurrence ID, canonical coordinate, and page
  number; they protect cycle/depth UI state but grant no read permission.
- Available children expose only allowlisted identity, title, and summary
  metadata; content/body HTML, media, and child Open actions are never returned.
  Missing, mismatched, suppressed, and tombstoned references share a generic
  unavailable label, retaining source occurrence identity without target
  metadata or disclosing whether a target exists. Sensitive child titles and
  summaries remain hidden.
- Nested expansion is another explicit lazy TOC disclosure. Occurrence/path
  hashes keep shared references' frame IDs distinct; server-issued HMAC state
  prevents callers from resetting or altering the ancestry and pagination
  bounds. Ancestry detects cycles, and expansion stops visibly at six index
  levels. No recursive auto-expansion, relay work, remote target lookup, or
  write is performed.
- Kind `30040` inventory entries containing nested indexes are no longer
  excluded from Reading Nook as magazines; they retain existing row actions
  and can use the TOC disclosure.
- This does not add My Content, generic note or `e` reference handling, kind `3`,
  or Bookshelf behavior.

### Shipped Step 2 subset: Reading Nook follow-pack npubs

- Owned kind `39089` rows have a separate native disclosure, not a replacement
  for the title link, Open/Manage links, or existing action menu.
- A lazy Turbo Frame loads up to 50 `FollowPackMember` occurrences per page.
  Pages are pinned to an exact event ID and retain signed tag order, positions,
  duplicates, and invalid references. No profile resolution, relay fetch,
  persistence, or source mutation takes place.
- Each GET rechecks `ROLE_USER`, the signed-in owner's pubkey, and stored kind
  `39089`; source author suppression and NIP-09 tombstones are checked before
  returning members. Responses are private and non-cacheable.
- The source adapter remains a concrete authorized-input adapter; this slice
  does not add a shared lookup or general-purpose source-adapter interface.
- The shared factory still scans the selected source event's tags in memory;
  pagination bounds output/npub encoding, not loading the source event's JSON.
- No kind `3`, Bookshelf, My Content, profile cards, or `a`/`e` expansion is
  included. These remain future workspace extensions outside the completed
  bounded Step 2 scope.

## Implemented foundation

The first slice is additive; it does not change Reading Nook, My Content,
embeds, or their access rules:

- `EventPresentationFactory` builds an immutable presentation from an
  already-authorized `Event` or explicitly supplied raw event fields. An
  Article projection, rendered HTML, database write, and relay lookup are not
  required. Identity derivation reuses `RecordIdentityService`.
- Recognized references are retained as ordered occurrences with source-tag
  positions, original values, target types, roles, relay hints, and invalid or
  unresolved states. Exact event IDs remain distinct from coordinates; kind
  `30040` and `30045` version fields are preserved separately. Bookmark,
  follow-list, and follow-pack profile occurrences have distinct roles. Kind `1` thread tags
  retain root/reply markers in either supported slot; kind `1984` report types
  are not relay hints, while supporting references can retain relay hints.
  Original tags, including malformed entries, remain available on the snapshot.
- Known text kinds can provide a bounded excerpt; opaque and potentially
  encrypted bodies are not excerpted. Content-warning and NSFW signals mark
  sensitive events, including qualified `nsfw` labels without an `L` tag. The
  static `Molecules:EventPreview` component renders escaped metadata without
  links, forms, media loading, or body conversion and hides the title and
  description of sensitive events by default, including when warning evidence
  conflicts with supplied flags. Kind `9735`'s serialized zap request is not
  used as a human-readable description.
- Characterization tests cover the existing inventories and representative
  preview paths, including their current limits and route-level role checks.
  My Content's inventory repository remains mocked; additional fragment tests
  exercise owner-scoped queries and real Twig rendering of disclosures,
  previews, and existing actions. Reading Nook's query uses SQLite. Full
  firewall and PostgreSQL coverage remains future work.
  `tests/NIPs/LOCAL-EVENT-WORKSPACE.feature` records local tag semantics;
  executable coverage is in PHPUnit because the feature specs are not part of
  the PHPUnit suite.
- `EventWorkspaceSourceAdapter` maps owned collection events, authored Article
  projections, local update subscriptions, and Bookshelf directories to typed
  source records. Event-backed records retain exact event IDs and raw ordered
  tags when those fields exist; Article projections that omit the ID or raw
  event tags report those fields as unavailable rather than inventing them.
  Replaceable identities retain their coordinate and pin the displayed event
  revision when its ID is known. Local subscriptions carry their target identity
  and stable owner scope without being represented as signed events. The
  adapter assumes its caller has already enforced access and performs no lookup
  or persistence.
- `EventWorkspaceResolutionResult` distinguishes available, missing, loading,
  unavailable, restricted, invalid, and failed states. An available result
  must match the requested exact event, coordinate/version, or profile identity.
  This is a result contract, not yet a shared lookup service.

The factory **does not authorize or resolve** either the source or its
references. Callers must enforce existing suppression, viewer, draft, and
private-content rules on the source before creating or displaying a
presentation. Authorize each target before exposing its data, counts, or
actions; the snapshot itself performs no target lookups. Callers must also gate
sensitive text, media, titles, and raw tags outside the default component. The
component is now used by My Content's owner-only preview fragments; a general
surface migration remains pending. Metadata and body text are untrusted, not sanitized HTML; escape them on
output and validate relay or media URLs before using them.

The inventory controllers do not yet consume the source records. A shared
source-adapter interface, authorized target resolver, expandable nodes, and
viewer registry are still needed. The workflow preflight must continue to block
migrations or viewers until their prerequisites are actually present.

For an event that the caller has already authorized:

```php
$presentation = $presentationFactory->fromAuthorizedEvent($event);
```

For a validated raw event that has not been persisted, use
`fromAuthorizedFields()` with its ID, kind, author pubkey, creation time, tags,
and content. The public DTO constructor instead expects display fields that have
already been interpreted for the source kind. Either factory result can be
passed to the presentation-only component:

```twig
<twig:Molecules:EventPreview :presentation="presentation" />
```

## Direction

Make the Nostr event, rather than a particular page or HTML projection, the
reusable unit of the application. Give each event a useful preview and a way to
explore its relationships. Evolve Reading Nook and My Content into two views of a
shared filesystem-like workspace, while retaining their focused reading and
publishing experiences.

Longer term, keep filesystem browsing generic while offering multiple compatible
viewers for the same event and independent display modes for its children.

The central invariant is:

**If readable event data is available, a preview and its outgoing relationships
are available without requiring an Article entity or pre-generated HTML.**

This is not a promise to reconstruct events that have not been fetched, decrypt
private content without authorization, or understand the body of every future
kind. Those cases still have explicit, useful identity/status views.

Prefer an incremental architecture change over a new framework or a full rewrite.
Keep Symfony, Twig, AssetMapper, Stimulus, PostgreSQL, Messenger, and the existing
relay infrastructure. Prove the component boundary inside Newsroom before
extracting another Composer package.

## What the existing implementation teaches us

| Evidence | Lesson for the next iteration |
|---|---|
| [Reading Nook](../../src/Controller/Reader/ReadingNookController.php) and [My Content](../../src/Controller/Newsroom/MyContentController.php) already present personal collections and authored items as filterable inventory rows with contextual actions. | These are the filesystem precursor. Extend their existing surfaces and extract common row/navigation behavior instead of introducing a competing workspace. |
| [NostrEmbed](../../src/Twig/Components/Molecules/NostrEmbed.php), [preview endpoint](../../src/Controller/DefaultController.php) (`nostrPreview`), [coordinate component](../../src/Twig/Components/Organisms/ArticleFromCoordinate.php), and [bookmark resolver](../../src/Twig/Components/Organisms/BookmarkItemResolver.php) each resolve and dispatch content differently. | A common lookup and presentation contract matters more than adding more individual preview templates. The same reference should not become less useful in a different context. |
| [Generic event body](../../templates/event/_generic_event_body.html.twig) already supports content, `alt`, `summary`, and common media tags. [The event page](../../templates/event/index.html.twig) has further kind-specific dispatch and tag extraction. | Preserve these capabilities, but move semantic interpretation out of page templates so every presentation can use them. Unknown kinds can still be readable. |
| `ArticleFromCoordinate` primarily expects Article storage and delegates chapters to [ChapterFromCoordinate](../../src/Twig/Components/Organisms/ChapterFromCoordinate.php). [ReadingListContentPreviewProvider](../../src/Service/ReadingListContentPreviewProvider.php) already demonstrates Article-to-Event fallback and batched metadata lookup. | Storage projections are adapters and optimizations, not prerequisites for a preview. Books API results also need an adapter; they must not require a synthetic persisted row. |
| [Converter](../../src/Util/CommonMark/Converter.php) combines body conversion, local reference lookup, and card rendering. [Deferred embeds](deferred-nostr-embeds.md) compensate for unavailable references through several resolution stages. | Keep document conversion separate from event resolution and composition. HTML is an output/cache, not the event model or the dependency graph. |
| [ReferenceParserService](../../src/Service/Graph/ReferenceParserService.php) parses `a` coordinates and now stores lowercase exact `e` event IDs. [GraphLookupService](../../src/Service/Graph/GraphLookupService.php) still joins only structural references to resolved current records. | The graph is a useful starting point, but it does not yet resolve exact `e` targets or cover follow-list `p` entries. A browser must retain unresolved entries rather than lose them through joins. |
| [RecordIdentityService](../../src/Service/Graph/RecordIdentityService.php) and [CurrentVersionResolver](../../src/Service/Graph/CurrentVersionResolver.php) already handle stable identities and revision selection. | Extend these mechanisms instead of inventing another coordinate/version implementation. Keep exact event references distinct from latest-version references. |
| [Async fetching](../../src/MessageHandler/FetchEventFromRelaysHandler.php) already uses Messenger and Mercure; its [browser controller](../../assets/controllers/content/event_fetch_controller.js) reloads the page. | Reuse the fetch infrastructure, but update just the affected occurrence without losing expanded branches, focus, or scroll. |
| [NKBIP-04](../NKBIP/04.md) defines drives and directories; [Bookshelf](bookshelf-local-fallback.md) uses kind `30045`. | Directory semantics and book resolution already exist. The two inventory pages are the UI precursor; expandable arbitrary-event traversal still needs to be added. |

These are architectural findings from source inspection, not a runtime audit.
The [architecture guide](../Guides/architecture.md) reflects the current extracted
packages; older assumptions about all bundles living under `src/` should not
drive the new boundaries.

## Starting point: the existing inventory workspaces

The previous version of this proposal overlooked the filesystem-like structure
already present in Reading Nook and My Content. Both are authenticated
workspaces with local navigation, flat inventory rows, filters, and per-item
actions. The next iteration should make those rows expandable, not start with
an unrelated third dashboard.

| Existing surface | What it provides today | Reuse and gap |
|---|---|---|
| [`/reading-nook`](../../templates/reader/reading_nook/index.html.twig) | Owned bookmarks, interests, follow packs, reading/curation lists, and update subscriptions; section tabs, search, timespan/tag filters, counts, Open/Manage menu, and a contextual aside. | Treat sections as virtual folders and collection rows as potential expandable nodes. Subscription records are local application state, not necessarily signed events. |
| [`/my-content`](../../templates/my_content/index.html.twig) | Owned published articles and drafts; status tabs, search, sorting, 25-row pages, editor/view links, coordinate copying, and signed deletion. | Use the same row/preview/expansion primitives with authoring-specific actions. Keep drafts and owner-only operations scoped to their owner. |
| [Reading Nook and Newsroom navigation](../../src/Helper/NavigationBuilderTrait.php) | Separate local sidebars under the shared shell, with existing routes for reading lists, magazines, and other workflows. | Preserve both routes, sidebars, and their reading-versus-publishing distinction; share the browser underneath them. |
| [My Books](bookshelf-local-fallback.md) | A kind `30045` directory and book/chapter lookup flows separate from Reading Nook. | Mount existing saved books into the reading view without copying or republishing the directory. |

Today the plus control in both inventories opens an **action menu**; it does not
expand event contents. Add a separate, clearly labeled disclosure control so
Open/Manage/Edit/Delete keep their existing meaning. Both pages currently require
`ROLE_USER`; a separately accessible public event view may reuse the components
without exposing either user's inventory.

The two controllers build different array shapes. Reading Nook's row model keeps
display metadata and links, but not the source event ID or raw tags needed for
expansion. Its tag helper lowercases, deduplicates, and sorts values for facets
and counts; it must not be used as the ordered list of child references. Its
event query caps results at 400 **before** deduplication and filtering, so
displayed totals can omit older owned items. It does not currently include
kind `3` follows or kind `30045` directories. Nested `30040` indexes are now
included in Reading Nook rather than hidden by the former magazine heuristic;
the existing 400-event inventory cap remains.

My Content currently loads only kinds `30023` and `30024`; lists and magazines
have separate navigation. Its repository collapses article revisions in
PostgreSQL, but the controller then filters, sorts, and paginates the latest
rows in memory. Move toward bounded, deterministic inventory queries as part
of the migration, while preserving the existing filters and action semantics.
The [My Content documentation](../Newsroom/my-content-page.md) describes the
current scope.

## Product model: a graph presented like a filesystem

An event can act like a file, a folder, or both. Expanding means revealing
meaningful structure, not merely displaying raw tags.

| Node | Default expansion | Preview |
|---|---|---|
| Follow list, kind `3` | Ordered `p` entries as profile nodes | Follow count, owner, revision; each entry has a profile preview or an npub fallback |
| Follow pack, kind `39089` | Member profile nodes | Title, description/image when provided, member count |
| Publication index, kind `30040` | Ordered table of contents, including nested indexes | Title, summary, cover, declared publication type, entry count |
| Chapter, kind `30041` | Explicit outgoing references; reading remains a separate action | Tag-derived title and summary, then a bounded text excerpt if needed |
| Directory, kind `30045` | Its `a` and `e` entries, including unresolved ones | Name/description and entry count |
| Drive, kind `30042` | Root directories | Drive metadata; supported after the first vertical slice |
| Bookmarks and curation lists | Kind-defined public members, including mixed reference types | Metadata and member count; private sections stay locked until authorized |
| Note, article, highlight, comment, or media event | Labeled relationships such as parent, root, mentions, and referenced events | Existing specialized presentation when supported, generic metadata/body fallback otherwise |
| Unknown kind | Recognizable references under a separate References group | Useful tag-derived metadata, safe text where meaningful, kind and identity |
| Profile node | Profile details, with explicit links to public lists/content | Cached metadata when available; npub remains usable without metadata |

The publication-index row describes the planned general workspace behavior.
The shipped Reading Nook slice is narrower: it resolves only visible local
nested kind `30040` indexes, returns allowlisted metadata without body or media,
and offers bounded lazy nested expansion. Other target kinds remain
reference-only.

Example:

```text
Reading Nook
  Bookmarks
    A note [note preview]
      References
        An article [article preview]
  Follow packs
    A pack
      Alice [profile preview]
  My follows [new kind 3 source]
    Bob [profile preview]
  My books [existing Bookshelf directory]
    A book [book preview]
      Introduction [chapter preview]
      Part one [index preview]
        Chapter one [chapter preview]
My Content
  Published
    An article [article preview and authoring actions]
  Drafts
    A draft [owner-only preview and edit action]
```

This is a graph, not a single-parent filesystem. One event can occur in several
collections; references can cycle; a mention is not ownership. Do not introduce
copy/move/delete or automatic reparenting semantics in the first iteration.
Do not silently rewrite existing lists into kind `30045` directories.

### Evolve the foreground workspaces

Keep Reading Nook and My Content as the primary entry points and add the same
expandable outline to their existing rows. On desktop, pair it with a
selected-item preview/reader pane. On small screens, use a single-pane
drill-down with breadcrumbs and Back preserving state. Existing section tabs
and filters remain useful ways to choose a starting collection; they are not
themselves signed folders.

Start from the existing Reading Nook sections and My Content's Published and
Drafts scopes. Add My follows from the user's kind `3` event and My books from
Bookshelf as explicit sources, rather than pretending they are already in the
Reading Nook query. Existing publication/reading-list navigation remains
available. These roots are local virtual groupings; actual NKBIP drives can be
mounted later.

Expanding a row reveals its children; a separate preview/selection action opens
the inspector. Keep existing title links, editor links, Open/Manage menus, and
the deletion confirmation intact. A book's expander opens the TOC, not all its
chapter bodies. Existing reading links and publication routes remain valid.
Provide an explicit Explore action on event/reader pages so their redirect
behavior does not prevent structural inspection. A public reference-opening
route can host the same browser without requiring a new personal dashboard.

Make selection and navigation shareable through a validated reference in the URL.
Store expanded occurrences and scroll locally for Back/Forward, without putting
private content into URLs or cross-user browser storage. Clear user-scoped
workspace state on sign-out/account changes. Keep Reading Nook's `q`, `section`,
`timespan`, and `tag` parameters, and My Content's `type`, `q`, `sort`, and
`page` parameters working through the transition.

## Later-stage direction: selectable viewers and display modes

The filesystem is a generic way to browse events, not a collection of
kind-specific applications that decide which events are allowed to appear.
Every readable event in the chosen inventory scope can use the same basic row
and inspector, including unknown kinds and events with no specialized viewer.
Saved/authored scope and access policy still determine inclusion; this does not
mean fetching every event from every relay.

Opening an event can then offer compatible, kind-aware viewers. In particular,
a publication index is not permanently classified as either a magazine or a
book: the reader can choose how to experience the same index and its referenced
events. A declared publication `type` can suggest the initial viewer, but should
not prevent switching to another compatible viewer or back to Generic.

Keep the following controls conceptually separate:

| Choice | Examples | What changes |
|---|---|---|
| Browser layout | List, thumbnails, gallery where appropriate | Arrangement of the same scoped inventory; events without imagery retain a useful labeled representation. |
| Event viewer | Generic, Magazine, Book | The reading experience for the selected event and its structure. Multiple viewers can support the same kind. |
| Children/member display | Indexes: titles, cards, full contents. Follow lists: npubs only, profile cards. | How much of each child/member is shown, independently of which parent viewer is selected. |

For example, one index could expose:

```text
View as:  Generic | Magazine | Book
Contents: Titles  | Cards    | Full contents
```

In titles mode, the TOC is a compact navigable outline. Cards add the reusable
event previews. Full contents renders the referenced events' readable bodies
in place, using each child's appropriate renderer, rather than only showing a
summary. A nested index can still have its own collapsed TOC and local display
choice; full contents must not mean recursively expanding the entire graph.
These modes reuse the same ordered child references and resolution results.

A follow list (kind `3`) or follow pack (kind `39089`) likewise offers:

```text
Members: Npubs only | Profile cards
```

Npubs-only mode displays the public keys from the `p` tags as npubs and needs
no kind `0` metadata to render. Profile-card mode resolves those same public
keys to their current kind `0` profile metadata through the shared cached,
batched resolver. Missing metadata leaves an npub-based entry visible while
any background refresh proceeds; it never removes a member or leaves a blank
card. Resolve only the visible page, not every member of a large list.
Profile names and images can update independently of the follow-list event;
the public key remains the member's identity in both modes.

Switching a viewer or display mode is a presentation operation: it must not
republish the event, change its tags, reorder its references, alter revision
bindings, or create a separate content copy. Preserve the selected child,
expanded branches, and reading position by stable reference/occurrence where
possible, rather than assuming different layouts share scroll coordinates.
Resolve viewer and display-mode choices using this precedence:

1. An explicit, validated URL option.
2. The user's saved preference for this context.
3. The event's declared type or kind-specific default.
4. The Generic viewer and its default display mode.

An event's declared type is a suggestion, not a restriction. Keep saved
preferences local to the user/context, and use validated URL options for
shareable public views. Neither preferences nor URL overrides mutate the
signed source event.

Use a small viewer registry when this stage is implemented. Each viewer
declares a stable ID, translated label, compatibility with the event/structure,
and supported display modes. The kind is a capability hint, not a one-to-one
mapping to a single viewer. Viewer adapters consume the shared event and
relationship models; they do not bring their own lookup, parsing, or ingestion
pipelines. Generic remains available even when no specialist is compatible or
one fails. Mixed or partially resolved indexes retain unsupported/missing
children as generic/status entries rather than silently dropping them.

Full contents remains explicit, paginated, lazy, and subject to the same
fetch/concurrency/depth, media-loading, and access rules as the browser.
Include viewer/display choices in rendered-fragment cache keys, while keeping
event resolution and normalized metadata reusable across modes.

This is a later milestone, not a prerequisite for the first expandable
inventories. Establish the data/presentation separation now, then introduce the
viewer selector and child display controls once the shared components are
stable. Do not require every layout/viewer/display combination to launch.

At that milestone, verify that the same index can switch between Generic,
Magazine, and Book without changing its identity or ordered references; its
children can switch between titles, cards, and full contents without losing
unresolved entries or expanding every descendant. Follow lists and follow
packs must switch between npubs and kind `0` profile cards over the same member
references, including when some profiles are unavailable. Npubs-only rendering
must not require metadata discovery. Unknown kinds must remain browsable, and
a failed specialist must leave the generic view usable.

## Shared architecture

The overall flow below is still proposed; the presentation/reference snapshot,
typed inventory source records, and resolution-result contract are implemented
pieces. Shared source adaptation and lookup are not implemented yet. These
boxes are not a requirement to create a class for each step.

```text
Nostr identifier / raw event / existing projection
  -> typed reference and source adapter
  -> authorized local resolution
  -> event presentation + typed relationships
  -> shared Twig components
       -> row / card / inline embed / detail / explorer branch

Missing target -> bounded background fetch -> re-resolve affected occurrences
Body requested -> format-specific conversion -> sanitized body output
```

### 0. Inventory sources and virtual roots

Extract the existing inventories behind small, independently testable source
adapters: owned collection events, locally stored subscriptions, authored
Article projections, and the Bookshelf directory. They provide typed node
identities and source-specific actions while sharing the event resolver and
presentation model. Do not fabricate Nostr events for virtual sections or local
subscriptions, and do not assume every item has an Article entity.

Keep the original event ID, address/author, and raw tags or a reference to
them alongside each event-backed row. Re-query the source by validated identity
and viewer context when expanding; never trust the browser to grant owner-only
actions. Preserve separate inventory scopes and show a shared event in each
relevant scope without duplicating or moving its underlying data.

### 1. Identity and resolution

Represent references explicitly: exact event ID, addressable coordinate,
replaceable kind/author lookup, and profile identity. Decode NIP-19 server-side
using the existing kernel/identity facilities; relay hints are lookup hints,
not part of identity. Preserve identifier case/content and use existing
canonicalization rules.

Resolve from authorized local sources first: raw Event, Article raw data/read
models, profile metadata, and the existing Books adapters. Return a typed result
that distinguishes available, missing, queued/loading, unavailable, restricted,
invalid, and failed. Keep presentation capability separate: a loaded event with
an unsupported body format is still available.

No leaf Twig component should perform synchronous relay/API discovery or write
events during rendering. Collect visible references for batched resolution;
schedule misses through shared fetch coordination and normal ingestion/projectors.
Reuse `FetchEventFromRelaysMessage`, `EventLookupKey`, and profile refresh
dispatching where suitable rather than building another relay client.

### 2. Presentation independent of the body

Produce an immutable presentation from the source event: identity, actual kind,
author reference, timestamps, title, description, media descriptors, warnings,
counts, body format/capability, and relationship descriptors. Preserve original
tags and tag positions; do not make a lossy normalized summary the source of truth.

Generic display precedence:

- Heading: useful `title`/`name`, then `d`, then a kind label and short identity.
- Description: useful `summary`/`description`, then `alt`, then an appropriate
  bounded plain-text excerpt. Exact precedence can vary for a known kind.
- Missing profile metadata: npub, never an empty author slot.
- No displayable body or metadata: explicit kind/identity view with any known
  relationship count, not "preview unsupported."

Use kind-specific semantics before generic heuristics. Profile JSON, encrypted
payloads, and opaque application data must not be blindly treated as Markdown.
Reuse deterministic Markdown/AsciiDoc mapping where appropriate. A conversion
failure preserves metadata and relationships, shows a body failure, and logs
the error; it never turns the entire preview into "not found."

### 3. Typed relationships and expansion

Extend the existing parser/index rather than adding a second graph. Separate
kind-aware interpretation from lookup. A small registered set of interpreters
can identify presentation and expansion behavior, with a generic fallback.
Adding a kind should not require editing a switch in every controller/template.

Relationships should retain source event ID, original tag index, target type,
target identity, relay hints, role, and any version binding. Use existing
pubkey fields for profile targets where appropriate. The nullable exact-ID
column and target-ID index are present; exact-target lookup behavior, remaining
relationship fields, and kind-specific interpretations are still follow-up
work.

#### Shipped bounded Step 4 slice: exact event-ID occurrences

- The existing parser/index now accepts valid lowercase `e` tags as exact
  event-ID references, stores their target IDs separately from address
  coordinates, and preserves ordered duplicate occurrences at original source
  tag positions. Uppercase `E` remains outside this generic parser.
- These new graph edges are contextual references by default, never structural
  or expandable. Parsing does not look up targets, so valid but locally missing
  IDs remain indexed occurrences. Kind-1 NIP-10 marker and relay metadata are
  retained; other `e` relay hints are stored without being used for network work.
- Ingestion, graph rebuild/backfill, and graph audit consume the same additive
  representation. Existing `a` coordinate parsing and publication/Unfold
  coordinate queries remain unchanged. This first graph slice did not yet
  include `p` edges; the separate profile-occurrence slice below adds them.
  Target resolution and broader traversal/index-vs-raw equivalence remain
  pending.
- Validation: the isolated Docker graph-service suite passes **75 PHPUnit
  tests / 179 assertions**. The migration has since been applied successfully;
  run `dn:graph:backfill-references` if existing events still need their `e`
  references indexed.

#### Shipped bounded Step 4 slice: profile occurrences

- Valid lowercase `p` references are indexed by normalized pubkey, preserving
  source ID, original position, and duplicates. Kind `3` uses `follows`, kind
  `39089` uses `member`, and other kinds use `references`; all are
  non-structural and do not yet support graph target resolution.
- The slot-2 WebSocket-scheme hint is untrusted and never used for network
  calls. Bounded JSON-encoded auxiliary slots preserve explicit tag data
  without assigning it a universal role or inferring a display name; raw
  signed tags remain authoritative when auxiliary data exceeds the bound.
  Uppercase `P` remains distinct.
- The new auxiliary-data migration must be deployed before graph writers and
  the reference backfill must be rerun for existing events. Graph audits sample
  reference counts, not full field equivalence. Profile resolution, exact-ID
  lookup and generic traversal remain pending.

Important semantics:

- A `p` tag in kind `3` means a followed profile; in another kind it may mean a
  mention, author, or recipient. It does not always create a folder member.
- `a` and `e` edges can be structural or contextual, according to the source kind.
  Keep uppercase/lowercase scope distinctions for comments and thread context.
- In the repository's NKBIP publication/directory specs, `a[3]` may be a version
  event ID. The current generic parser treats it as a marker. Interpret that
  field by kind/spec rather than applying one universal tag layout.
- Respect declared publication order, version bindings, and `auto-update`
  behavior. An explicit `e` reference stays exact even if its event is replaceable.
  An unpinned address reference resolves the winning revision using existing
  rules. Before exposing a pinned `a` target, verify that the exact event's
  kind, author, and identifier match its coordinate; a mismatch is not a
  reason to silently substitute the latest revision.
- Unknown references belong in References, not automatically in Contents.
- Preserve unresolved and duplicate occurrences and their positions. Batch-fetch
  targets without globally deduplicating the visible structure.

For expansion, enumerate edges first and left-resolve their targets. Do not use
the current inner-join children query as the sole browser API: it omits missing
targets. Raw tags provide immediate edges if the derived graph is absent/stale.
The graph remains a rebuildable acceleration layer, never a preview prerequisite.

Paginate edges in stable source-event/tag-index order. Bind page cursors to the
parent revision so a replaceable list changing mid-pagination triggers a clean
refresh rather than skipped/repeated entries. Report total declared entries
separately from resolved entries.

### 4. Nestable component family

Build a shared event preview with explicit modes: row, card, inline, and detail.
Compose it with profile preview, body, relationship list, reference/status row,
and an expandable node shell. The shell owns expansion state; the preview owns
neither fetching nor a whole page layout. Specialized article/media/chapter
templates can initially sit behind adapters. Begin with the existing
`reading-nook-row` and `my-content-row` layouts and extract their common
structure without forcing their different metadata columns or actions to match.

Keep component placement (row/card/inline/detail), selected specialist viewer,
and child display density distinct. This leaves room for the later viewer and
TOC controls without turning each combination into a separate event model or
duplicating components.

Use Twig components for presentation, Live Components where server-backed local
state is useful, Turbo frames for lazy branch/pane HTML, and Stimulus for
expansion/focus behavior. Avoid one heavyweight Live Component per follow entry.
Pass typed data and a render context, not a mixture of entity/stdClass/array
shapes, ambient route variables, or pre-rendered HTML.

Render context carries presentation mode, viewer policy, ancestry, and remaining
depth/node budget. Detect cycles along the current path, not through a global
visited set: the same chapter may legitimately appear in two branches.
Use occurrence keys based on parent occurrence, source revision, and tag index,
not only event ID. Each occurrence gets unique DOM/frame IDs and independent state.
At a cycle/depth boundary render a compact reference with an Open action.

Nested cards must avoid nested anchors/forms and uncontrolled automatic embed
expansion. Rich body rendering is explicit; a collapsed row never recursively
converts every descendant body.

## Reliability, policy, and loading rules

Start with proposed defaults of 50 child rows per page, 3 automatic embed levels,
and at most 4 concurrent browser fragment requests. Explicit expansion can go
deeper through new requests; it must not recursively load the rest of the graph.
These are acceptance targets for the first implementation, not measured current
performance.

Batch profile metadata and event lookups for the visible page. Deduplicate active
fetches by target and relevant viewer/relay scope, with retry/backoff and bounded
negative caching. Expanding one 1,000-entry follow list must not schedule 1,000
relay/profile jobs. Hidden/collapsed branches do not start discovery; reopening
a loaded branch reuses its result.

Mercure should invalidate/update the affected branch/card, not reload the whole
workspace. Provide a bounded status recheck when SSE is unavailable or its update
arrived before subscription. Distinguish timeout, failed lookup, and empty
collection; avoid indefinite spinners and success-shaped error HTML.

Cache normalized presentation by event ID and presentation version. Cache body
HTML separately by event ID, format, and renderer version. Resolve latest
coordinates through current-version state; update affected views when the winning
event changes. Keep viewer-sensitive data in scoped caches, not public body/card
caches. Persist document embed references as inert reference slots rather than
permanently freezing resolved child cards into converted HTML. During migration,
continue reading existing processed HTML; invalidate/rebuild it deliberately.

Apply existing author suppression, deletion/tombstone, scoped-content access,
user-mute, and content-warning rules before lookup results, counts, child pages,
fragment responses, caches, or notifications expose data. Preserve their distinct
policies; the explorer is not a bypass. Do not publish private fetch identifiers
on public Mercure topics. Do not decrypt private lists automatically or fetch
arbitrary relay/media URLs without the application's validation/egress policy.

The outline needs labeled disclosure buttons, `aria-expanded`, usable keyboard
focus, loading/error announcements, and keyboard-accessible previews rather than
hover-only cards. Use ordinary nested lists/disclosures unless implementing the
complete ARIA tree keyboard pattern. Preserve project styling and localization;
media should not autoplay or load merely because a folder was expanded.

## Delivery plan

| Step | Scope | Status and current completion boundary |
|---|---|---|
| 1. Capture the contract | Characterize both existing inventories, their filters, access rules, actions, and previews. Introduce typed source/reference/result adapters, reusing current identities. | **Complete for the current contract foundation.** A readable raw event produces a generic presentation without Article storage, body HTML, or network access; typed source/reference/result contracts exist, and the characterized Reading Nook and My Content routes retain their behavior. Broader PostgreSQL/firewall coverage is follow-up hardening; defects found there are bugs. |
| 2. Ship the vertical slice | Add shared row/preview and expandable-node components to Reading Nook and My Content behind a rollout flag. Expand selected existing collection rows and the Bookshelf directory. | **Complete for the shipped bounded surfaces.** Follow-pack members, bookmarks, bounded kind `30040` local nested indexes, Bookshelf directory disclosures, My Content previews, shared DB-only bookmark/directory target metadata, and lazy chapter previews are delivered behind the existing rollback flag. Kind `3` previews, general recursion, broader article entitlement, and selectable viewers are intentionally outside this milestone and remain future work. |
| 3. Make discovery resilient | Provide bounded user-triggered discovery, per-occurrence updates, retry/status fallback, and preserved local disclosure state for the supported TOC path. | **Complete for the supported Reading Nook TOC path.** Unpinned public `30040`/`30041` discovery, per-occurrence polling/retry limits, and separately authorized lazy previews for available `30041` chapters are implemented with targeted Docker PHPUnit and Node coverage. Cold exact-pin fetching, general-purpose coordination, and Books/profile adapters are future extensions. Browser/live worker/Redis and deployment-runtime validation remain follow-up checks; failures found there are handled as bugs. |
| 4. Extend the graph | Add and maintain the bounded typed `p`/`e` parsed-reference representation without changing existing publication/Unfold coordinate behavior. | **Complete for the delivered parsed-reference indexing scope.** Lowercase exact `e` occurrences and source-kind-aware `p` occurrences are indexed with positions/duplicates and carried through migrations, ingestion, rebuild, backfill, and audit writers. General target resolution/traversal, additional version-binding behavior, mixed collection expansion, and full indexed-vs-raw equivalence are future extensions, not blockers for this milestone. |
| 5. Converge existing surfaces | Route `/api/preview/`, deferred embeds, coordinate cards, bookmarks, comment context, and event pages through the common contracts. Separate body conversion from embedded event rendering. | **Complete for the delivered bounded migration targets.** `/api/preview/` exact-ID and `naddr` slices, immediate/local `NostrEmbed`, deferred exact-reference client/API parity, NIP-22 parent/root address previews, `BookmarkItemResolver` and bookmarks-page exact-event previews, local non-magazine chapter coordinate cards, and supported highlight coordinate previews are shipped. `note`/`nevent` exact-ID responses and local kind `30040` `naddr` API fallbacks use the shared event presentation; exact IDs, coordinate lookup, ordered references, and DB-only rendering remain distinct. Author suppression, NIP-09 tombstones, hidden coordinates, authenticated strict NIP-51 mutes, and Essayist-exclusive Article entitlement are checked before presentation or Article adaptation. Local kind `30023` Article, kind `30041` chapter, and kind `30818` wiki API previews use shared event presentations, retain focused links and cold fetch/view links, and return metadata-free warning fragments. Local Article API previews verify the complete signed raw envelope and its agreement with the Article projection before adaptation; invalid or mismatched envelopes fail closed. Their decoded coordinate is bound to the request's original Bech32 `naddr`, and conflicting identity fields are rejected. The `npub`/`nprofile` API path derives identity from the original Bech32 reference and reuses the existing profile card, rather than trusting decoded browser fields. Local `NostrEmbed` uses compact shared previews for a public-kind allowlist and exact-projection-authorized longform; when an Event row is absent, a complete signature- and event-ID-verified raw Article envelope with matching projection identity and timestamp can supply the Article snapshot only after the same visibility and entitlement checks, without fabricating an Event or rendering processed HTML. A matching local longform Event without its Article projection exposes no metadata and retains only the focused deferred link; projection lookup failures and mismatches fail closed. Warnings hide metadata, denied/failing lookups fail closed, and genuinely missing references retain their deferred fallback. Kind `1111` comment parent/root `a`/`A` tags use that same local-only compact path, retaining first occurrences, source coordinates, relay hints, and parent/root duplicate suppression; external `i` references and comment content are unchanged. Both highlight feed surfaces use guarded embeds for kind `30023`, `30040`, and `30818` source coordinates; kind `30041` retains its Books-backed chapter path. Highlight source IDs use valid lowercase NIP-84 `e` tags, and exact event references take precedence over coordinate lookup when both are present. Highlight detail pages also route exact event sources through that guarded path and disable shared caching for viewer-specific mute checks. The feed service carries coordinate relay hints into the encoded address without changing relay path identity. The exact API accepts validated raw browser Bech32 payloads and legacy decoded IDs, applies the same public-kind allowlist and exact matching longform projection/entitlement gates, and returns metadata-free warnings from either signed source; it never falls forward, fetches, persists, or converts bodies. Bookmark previews preserve exact local `e` and relay-preserving `a` identity, missing-event Fetch/error behavior, profile/topic behavior, signed removal, order, and duplicates; see [bookmarks](bookmarks.md). These are explicit boundaries, not claims that every specialist card or profile inventory has migrated: unsupported conversion-time card kinds, other bookmark callers, broader profile inventory surfaces, and browser/live-worker/deployment validation remain independent follow-up work. Targeted coverage and verification records are linked in [bookmarks](bookmarks.md) and [deferred embeds](deferred-nostr-embeds.md). |
| 6. Promote and stabilize | Enable expansion in the existing workspaces broadly; add drive mounting, saved local workspace state, performance instrumentation, and user-facing documentation. Evaluate package extraction after a second consumer proves the boundary. | Browsing works for cold/partial data, deep/shared/cyclic structures, mobile, keyboard navigation, and restricted content. Existing reader/publisher routes remain intact. |
| 7. Add selectable viewers (later) | Introduce compatible Generic/Magazine/Book viewers, titles/cards/full-contents controls for indexes, npub/profile-card modes for follow lists, and alternate browser layouts where useful. Reuse shared event resolution and components. | The same event and ordered children/members can be viewed in different ways without changing source events, losing navigation state, or requiring a specialist for generic browsing. |

### Bounded Step 5 event-page note slice

- The existing `/e/{nevent}` route now composes `EventPreview` and the
 existing full generic body and metadata renderers from an
 `EventPresentationFactory` snapshot for local kind `1` notes only.
 The snapshot retains original tags and ordered references; body conversion
 and existing media, topic, source and reference links remain separate.
- Before creating the snapshot the controller applies the shared suppression,
 NIP-09 tombstone and strict reader-mute policy; policy failures deny the page.
 These viewer-dependent responses are private and `no-store`. The shared
 factory does not authorize or fetch.
- Notes with content-warning/sensitive tag evidence, ambiguous/unmarked NIP-10
 `e` tags, marked roots/replies, `h`/`A` scope or legacy NIP-72 `34550:` `a`
 coordinates, relay-only results, unknown kinds and specialist branches are
 excluded from the new preview path. They retain the prior event-page behavior,
 including its existing access and caching limitations; exclusion is not a new
 authorization policy for those legacy pages. In particular scoped unknown
 kinds are not passed to the shared preview without a separate scope policy.
 Existing Article, chapter, publication and comment routing is unchanged.
- Explicit standard-slot `mention` references with a valid event ID and an empty
 or WebSocket-scheme relay hint remain eligible; malformed `a` tags and ambiguous
 or conflicting `e` markers stay on the legacy page path.
- This does not address the existing synchronous event/OP relay fetches, expand
 references, or migrate warning/reveal behavior and private/scoped kinds. Those
 require separate authorization and page-parity work.

The Step 5 bookmarks-page exact-event caller reuses the controller's existing
batch-resolved Events through mount-only resolver/embed inputs. Local duplicate
and distinct occurrences add no Event lookups; exact identity and shared
access/warning/Article entitlement checks still run. Cold Fetch/error behavior
is unchanged. This corrects the caller migration's initial redundant reads,
not the broader policy/Article-query batching backlog. Narrow Docker coverage
passes 86 tests / 618 assertions plus Twig/PHP and container lint; see
[bookmarks](bookmarks.md).

### Bounded Step 5 conversion-time shared event-card slices

Locally batch-resolved kind `1` `nevent` cards and kind `30023` Article and
`30040` publication-index, `30041` chapter, and `30818` wiki `naddr` cards in
`Converter::renderNostrLink()` emit the same inert deferred placeholder as
missing targets. The original Bech32 identity and hints remain intact; final
`resolve_nostr_embeds` rendering applies guarded `NostrEmbed` exact-ID or
coordinate access, warning and compact presentation policy instead of saving
legacy card metadata. Inline links, kind-20 `note` pictures, non-kind-1
`nevent` paths, unsupported `naddr` cards, and focused chapter/wiki routes
remain unchanged. The local Article API preview now uses the shared snapshot
only after signed envelope and projection verification. No synchronous fetch or
persistence is added. Existing stored rich-card HTML is not backfilled by this
slice.
Focused coverage reuses `NostrChapterEmbedTest`; see
[deferred embeds](deferred-nostr-embeds.md).
Docker validation for the conversion-time exact-reference and supported
coordinate slices passes 74 tests / 306 assertions across the converter,
placeholder runtime, and shared embed suites, plus PHP syntax and diff checks.

Local kind `30023` Article `/api/preview/` targets now verify the raw signed
envelope and its agreement with the Article projection before rendering the
shared presentation and focused Article link. Invalid signatures and
projection mismatches fail closed. Signed warning evidence returns the
metadata-free warning fragment; cold Articles keep their existing fetch/view
link. Local kind `30041` chapter and kind `30818` wiki API targets retain the
same shared presentation, focused links, warning behavior, and cold links.

The activity bookmark-card partial remains unchanged: its only live activity
caller selects kinds `9802`, `16`, and `1111`, not kind `1`.

The separate Step 5 comment-feed slice migrates only the address branch of
`_comment_as_card` to the guarded local `NostrEmbed`. It retains root/parent
precedence, surrounding callout/card and other reference branches. A narrow
round-trip-checked coordinate filter fails closed on malformed or unencodable
input; valid cold references keep the existing local preview API fallback.
No synchronous relay fetch or persistence is added. The legacy coordinate
component, bookmark card, and other magazine callers remain unchanged; see
[deferred embeds](deferred-nostr-embeds.md).

The magazine-wizard review list separately uses the guarded shared preview for
kind-30023 article coordinates. It retains list order, fails closed if a
coordinate cannot be round-trip encoded, and leaves kind-30041 chapters and
other coordinates on their existing specialist component. Local Event and
Article projection policy is enforced by `NostrEmbed`; it does not fetch or
persist a missing event.

Steps 3 and 4 provide bounded discovery and indexing for the shipped surfaces;
they are not claims of a general resolver or complete graph browser.
Convergence and broad rollout remain later work and may use raw tags where the
graph index does not yet cover a target.

After the shared contract, generic preview, and source-adapter interface exist,
the optional [event workspace agent workflows](../Guides/event-workspace-workflows.md)
can carry out later migrations and viewers one target at a time. They do not
replace the foundation work or automatically advance to another target.

Keep migration additive. Add new relationship queries rather than silently
changing the existing graph API used by publications/Unfold. Backfill in bounded
batches, retain raw-event fallback, and compare indexed/raw outputs before relying
on the index. The UI flag provides rollback without rolling back signed data.
Do not move application-specific moderation, billing, or relay selection into a
generic preview package.

## Broader acceptance and verification backlog

The scenarios below describe the broader workspace/product acceptance target;
they are not blockers for marking Steps 1–4 complete for the bounded scope in
the delivery table. The delivered scope has focused PHPUnit, Twig/YAML, and
JavaScript coverage as recorded above. Where broader or live checks have not
been run, do not claim they passed. Any defects those checks later reveal are
bugs to address in follow-up work.

- Reading Nook and My Content keep their existing URLs, filters, scoped
  inventories, Open/Manage/Edit actions, and signed-delete confirmation. Their
  disclosure controls are distinct from their action menus.
- The Reading Nook can paginate past the current 400-event cap without losing
  older owned collections or reporting truncated totals. A `30040` containing
  another `30040` is now included in the inventory and locally expandable with
  exact pin, suppression/tombstone, cycle, depth, metadata, and frame-identity
  checks; the broader workspace and non-index target behaviors remain pending.
- Raw kind `30023` without an Article row or processed HTML still has a useful
  preview. A body-converter failure leaves its metadata and relationships usable.
- An unknown kind with empty content displays `alt`/summary/media where available;
  an entirely empty event still shows kind, identity, author, and explicit status.
- A follow list displays every valid member in order across pages; missing
  metadata retains an npub preview and enriches asynchronously.
- A book/index preserves nested order and unresolved entries, including a TOC
  assembled from partial data. Version-pinned references do not drift to latest.
- Available kind `30041` TOC occurrences can be opened as separately authorized
  lazy previews; initial TOC rendering never includes chapter bodies, and
  missing, changed, muted, hidden, suppressed, or tombstoned targets disclose
  no body or target metadata.
- Directory `a`/`e` members, references whose identifiers contain colons, empty
  valid `d` identifiers, malformed tags, and mixed case pubkeys behave predictably.
- Shared children appear in both branches; self-references and A-to-B-to-A cycles
  terminate visibly. DOM/frame IDs remain unique.
- Expanding a 1,000-member fixture initially renders at most 50 members, resolves
  them in batches, does not schedule discovery for the other 950, and stays within
  the 4-request browser concurrency bound.
- Fetch completion updates only affected occurrences and preserves expanded
  siblings, focus, scroll, and Back/Forward state. Missed SSE has a bounded recovery.
- Deleted, suppressed, muted, private/scoped, and content-warning cases follow
  existing policy at every surface; sign-out does not retain private previews.
- The same fixtures render in row, card, inline, and detail modes without relying
  on the parent page's route/variables. Existing math, Markdown/AsciiDoc, media,
  chapter, comment, and publication behaviors retain regression coverage.

Use PHPUnit unit tests for semantic interpretation, identities, source adapters,
and state transitions; PostgreSQL integration tests for graph equivalence,
pagination, revision selection, and policy-aware queries; component/HTTP tests
for every presentation mode; and browser interaction coverage for nested
expansion, fragment replacement, accessibility, and navigation state. Use the
existing JavaScript test runner where it covers controller logic, with an
explicit browser verification pass for behavior it cannot exercise.

Record query counts, queued fetch counts, fragment response times, and resolution
outcomes. Establish latency baselines on a documented Docker fixture before
setting a production latency budget; do not claim a performance improvement from
component reuse alone.

All implementation checks run in Docker. Add one changelog item per delivered
feature/fix and update this document as decisions become implemented. The
standalone foundation now supports the bounded follow-pack, bookmark, nested
publication-index, and Bookshelf directory slices above, not yet a
general-purpose expandable workspace.

## Follow-up validation and workspace direction

The narrow Reading Nook demonstration is implemented: selected collection
rows can expose bounded disclosures, nested indexes remain local-only during
rendering, missing public `30040`/`30041` addresses can use explicit bounded
discovery, and available chapters have separately authorized lazy previews
without Book mounting or recursive discovery. The immediate follow-up is
browser/live Docker validation on a cold-reference fixture (duplicate unpinned
occurrences, locally available exact pins, sibling disclosures, sign-out/policy
changes, and failed workers); this is additional confidence work, not a blocker
for the completed bounded milestones above.

Other kinds still need separate policy/scoping decisions. Reuse the row/preview
in My Content and inside an ordinary event page without changing their existing
actions. Kind `3` follow-list previews are intentionally skipped. Bookshelf
currently exposes its ordered directory references on My Books rather than
mounting them in Reading Nook.

That demonstrates the two main goals together: reusable components that really
nest, and a foreground explorer driven by event structure rather than by whether
someone has already generated HTML.
