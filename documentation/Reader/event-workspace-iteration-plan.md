# Event Workspace and Composable Previews

Status: the signed-event presentation and reference snapshot is implemented.
Typed source-record and resolution-result contracts are also implemented but
are not wired to either inventory route. The shared lookup service, expandable
workspaces, and selectable viewers remain proposed.

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
  `30040` and `30045` version fields are preserved separately. Follow-list and
  follow-pack profile occurrences have distinct roles. Kind `1` thread tags
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
  My Content's repository and both Twig renders are mocked; Reading Nook's
  query uses SQLite. Full firewall and PostgreSQL coverage remains future work.
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
component is a building block, not yet connected to an existing user-facing
route. Metadata and body text are untrusted, not sanitized HTML; escape them on
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
| [ReferenceParserService](../../src/Service/Graph/ReferenceParserService.php) currently parses `a` tags. [GraphLookupService](../../src/Service/Graph/GraphLookupService.php) joins structural references to resolved current records. | The graph is a useful starting point, but it does not yet cover follow-list `p` entries or immutable `e` targets. A browser must retain unresolved entries rather than lose them through joins. |
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
kind `3` follows or kind `30045` directories, and treats a `30040` with a
`30040` child as a magazine rather than a Reading Nook list. Nested books must
not disappear because of that heuristic.

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
target identity, relay hints, role, and any version binding. Add target event-ID
storage and the indexes needed for `e` edges; use existing pubkey fields for
profile targets where appropriate. Plan a migration against the actual
`parsed_reference` schema, which currently has no target event-ID column.

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

| Step | Scope | Exit criterion |
|---|---|---|
| 1. Capture the contract | Characterize both existing inventories, their filters, access rules, actions, and previews. Introduce typed source/reference/result adapters, reusing current identities. | One readable raw event can produce a generic presentation without Article storage, body HTML, or network access; both inventory routes retain their behavior. |
| 2. Ship the vertical slice | Add shared row/preview and expandable-node components to Reading Nook and My Content behind a rollout flag. Expand existing follow-pack/list rows, and add explicit kind `3` follows and Bookshelf sources. | A follow list expands into npubs with profile previews; a book index expands into ordered nested TOC entries. My Content uses the same component while retaining authoring actions. |
| 3. Make discovery resilient | Shared bounded fetch coordination, per-fragment updates, retry/status fallback, and preserved browser state. Adapt Books/profile sources without render-time discovery. | Cold references progressively resolve without full-page reload, losing siblings, or unbounded fan-out. |
| 4. Extend the graph | Add typed `p`/`e` relationships and version-binding fields, migrations/indexes, rebuild/backfill, and audit coverage. Add directories and mixed bookmark/curation expansion. | Indexed and raw-tag expansion give equivalent ordered results, including missing targets. Existing publication/Unfold queries retain their behavior. |
| 5. Converge existing surfaces | Route `/api/preview/`, deferred embeds, coordinate cards, bookmarks, comment context, and event pages through the common contracts. Separate body conversion from embedded event rendering. | A supported event has consistent identity, metadata, relationships, and status across all migrated contexts. Remove superseded dispatch paths only after parity. |
| 6. Promote and stabilize | Enable expansion in the existing workspaces broadly; add drive mounting, saved local workspace state, performance instrumentation, and user-facing documentation. Evaluate package extraction after a second consumer proves the boundary. | Browsing works for cold/partial data, deep/shared/cyclic structures, mobile, keyboard navigation, and restricted content. Existing reader/publisher routes remain intact. |
| 7. Add selectable viewers (later) | Introduce compatible Generic/Magazine/Book viewers, titles/cards/full-contents controls for indexes, npub/profile-card modes for follow lists, and alternate browser layouts where useful. Reuse shared event resolution and components. | The same event and ordered children/members can be viewed in different ways without changing source events, losing navigation state, or requiring a specialist for generic browsing. |

Step 4 is an optimization/generalization, not a dependency for the first useful
workspace: the vertical slice can enumerate raw tags. Steps 3 and 4 both build on
step 2; convergence and broad rollout require their relevant loading/index work.

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

## Acceptance and verification

The first release is complete only when these scenarios are covered:

- Reading Nook and My Content keep their existing URLs, filters, scoped
  inventories, Open/Manage/Edit actions, and signed-delete confirmation. Their
  disclosure controls are distinct from their action menus.
- The Reading Nook can paginate past the current 400-event cap without losing
  older owned collections or reporting truncated totals. A `30040` containing
  another `30040` remains accessible as a nested publication/book.
- Raw kind `30023` without an Article row or processed HTML still has a useful
  preview. A body-converter failure leaves its metadata and relationships usable.
- An unknown kind with empty content displays `alt`/summary/media where available;
  an entirely empty event still shows kind, identity, author, and explicit status.
- A follow list displays every valid member in order across pages; missing
  metadata retains an npub preview and enriches asynchronously.
- A book/index preserves nested order and unresolved entries, including a TOC
  assembled from partial data. Version-pinned references do not drift to latest.
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
standalone foundation does not yet change the user-facing workspace.

## Recommended first workspace slice

Build one narrow, end-to-end demonstration in Reading Nook: expand an existing
follow pack and a reading list, then expose the user's kind `3` follow list and
Bookshelf book directory through the same node. Preview a profile and a chapter,
expand a nested index, and resolve a missing child asynchronously without
disturbing the rest of the outline. Reuse the row/preview in My Content and
inside an ordinary event page without changing their existing actions.

That demonstrates the two main goals together: reusable components that really
nest, and a foreground explorer driven by event structure rather than by whether
someone has already generated HTML.
