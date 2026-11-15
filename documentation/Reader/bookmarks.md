# Bookmarks Feature

## Overview

Bookmarks use NIP-51 kind 10003 events. Kind 10003 is a **replaceable** event (NIP-01, range 10000–19999), meaning only one event per npub should exist — the latest one replaces all previous versions.

Each bookmark entry is stored as a tag in the event:
- `["e", "<event-id>"]` — bookmarks a specific note/event by ID
- `["a", "30023:<pubkey>:<d-tag>"]` — bookmarks a long-form article by coordinate
- `["t", "<hashtag>"]` — bookmarks a hashtag/topic
- `["p", "<pubkey>"]` — bookmarks a user profile

## Bug Fix: Deduplication

### Problem
Kind 10003 events were not being deduplicated in the bookmarks display. If a user had multiple kind 10003 events in the database (e.g., from events ingested before the `GenericEventProjector` deduplication logic was added), all of them would be displayed on the bookmarks page.

### Solution
1. **Display-time deduplication** in `BookmarksController::loadBookmarks()`: after querying bookmark events from the DB, the controller now deduplicates:
   - Kind 10003 (replaceable): keeps only the newest event per pubkey+kind
   - Kinds 30003–30006 (parameterized replaceable): keeps only the newest event per pubkey+kind+dTag
2. **Automatic cleanup**: stale duplicate events detected during deduplication are deleted from the database in the same request.
3. **Ingestion-time deduplication**: the `GenericEventProjector` already handles this for newly ingested events via `removeOlderReplaceableVersions()`.

## Feature: Bookmark Button on Articles

### User Flow
1. User views an article while logged in
2. A "Bookmark" button appears in the article actions bar (alongside Reading List, Zap, Broadcast)
3. On page load, the button fetches the user's current bookmarks from `/api/bookmarks/current`
4. If the article is already bookmarked, the button shows a filled bookmark icon
5. Clicking the button:
   - Gets the user's Nostr signer (NIP-07 extension or NIP-46 remote signer)
   - Builds a new kind 10003 event with all existing tags preserved, plus the article's `a` tag added (or removed if unbookmarking)
   - Signs the event via the signer
   - POSTs the signed event to `/api/bookmarks/publish`
   - The backend persists via `GenericEventProjector` (proper replaceable event dedup) and broadcasts to the user's relays
6. The button toggles visually (filled/unfilled) after successful publish

### Technical Architecture

#### Backend

**API Endpoints** (`BookmarksController`):

| Method | Path | Route Name | Purpose |
|--------|------|------------|---------|
| GET | `/api/bookmarks/current` | `api_bookmarks_current` | Returns the user's current kind 10003 event tags as JSON |
| POST | `/api/bookmarks/publish` | `api_bookmarks_publish` | Receives a signed bookmark/list event, validates ownership, persists, and broadcasts |

**Publish flow**:
1. Validate signed event structure and kind (must be 10003)
2. Verify event signature via the host `NostrEventVerifier` adapter over Innis core
3. Persist via `GenericEventProjector::projectEventFromNostrEvent()` — this handles:
   - Checking for existing newer versions
   - Persisting the new event
   - Deleting older replaceable versions (NIP-01 semantics)
4. Broadcast to user's relays via `NostrClient::publishEvent()`
5. Return relay success/failure summary

#### Frontend

Bookmark toggling is handled by `ui--card-bookmark` on article cards and in
the `ArticleSocialActions` component. Both use the shared IndexedDB snapshot
and signed publish flow described below. The secondary
[article actions dropdown](article-actions-dropdown.md) handles Nostr identifiers
and broadcasting.

The bookmarks page uses `ui--bookmark-list` for removal. It preserves the
current list's non-target tags and content, removes the selected `e`, `a`, `p`,
or `t` tag, asks the user's signer to sign the replacement event, and publishes
it through the same endpoint. Standard kind 10003 removals also update the
shared IndexedDB bookmark snapshot and notify article-card bookmark controls.
Within `BookmarkItemResolver`, coordinate bookmarks use the same standalone
signed list-removal control as event/profile/topic bookmarks: the compact
preview has no article-card `Bookmarked` shortcut. Other article-card surfaces
retain their existing shortcut.
`BookmarkItemResolver` remains a Live Component only for lazy event fetching;
bookmark removal is deliberately not a LiveAction because the server cannot
sign on the user's behalf.

### Bounded Step 5: BookmarkItemResolver previews

The initial slice migrated only
`templates/components/Organisms/BookmarkItemResolver.html.twig`:

- A locally resolved `e` reference renders through `Molecules:NostrEmbed` as
  an exact `note` ID, using the original bookmark value rather than a latest
  coordinate lookup. This is a compact metadata preview, not body conversion
  or a kind-specific bookmark card.
- A coordinate `a` reference uses the existing `naddrEncodeCoordinate(relay)`
  filter and the shared `naddr` preview. The kind, author, complete identifier
  (including colons), and optional relay hint survive encoding and navigation.
  Article, chapter, and other event routes remain distinct. No projection-only
  or cross-kind Article lookup is used to grant access.
- The shared component resolves locally (or reuses the resolver's Event) and applies author suppression,
  NIP-09 tombstones, hidden coordinates, authenticated strict mutes, and its
  public-kind allowlist before presentation. Longform additionally requires the
  exact matching Article projection and entitlement. Warnings produce a
  metadata-free notice; denied/failed targets fail closed.
- A missing `e` reference **does not mount NostrEmbed**: its existing explicit
  `fetchEvent` LiveAction button remains. Ordinary mount/render does not fetch
  or persist it. The action still passes the stored relay hint, persists a
  successful result, and keeps its existing missing/error messages. Newly
  resolved events then pass through the same shared preview checks.
- Cold `a` references retain the shared deferred-placeholder behavior; this
  does not add synchronous relay work to rendering. Local coordinate selection
  and deferred API specialist parity retain the limitations documented in
  [deferred embeds](deferred-nostr-embeds.md).
- Profile (`p`) previews, topic (`t`) links, and raw removal targets are
  unchanged. Coordinate rows reuse the existing removal partial and browser
  signer flow instead of losing removal with the old card shortcut. The
  authenticated owner inventory and signature/owner validation in the publish
  endpoint are unchanged; preview props never authorize a bookmark mutation.

#### Follow-up: bookmarks-page pre-resolved exact events

`templates/pages/my-bookmarks.html.twig` now routes its formerly pre-resolved
`e` branch through the same `Organisms:BookmarkItemResolver` as its other rows.
The bespoke card bypass is removed from this one caller; the partial and its
Essayist activity caller remain unchanged. Profile/topic and coordinate paths
are not redesigned.

The original item loop, order, duplicate occurrences, exact bookmark values,
relay hints, row layout, count, signed tags/content, removal controls, and
signer/publish configuration are retained. The resolver explicitly accepts its
reference inputs in `mount()`, because Twig invokes mount before assigning
remaining public properties. Thus a local exact event enters the guarded shared
preview immediately rather than incorrectly showing Fetch. Cold/missing and DB
error rows keep their existing explicit Fetch/error behavior; rendering adds no
network or persistence and does not fall forward to a coordinate revision.

The controller, owner inventory, routes, and signed publish endpoint are
unchanged. The page passes each local `e` target from the controller's existing
`findByIds()` / `resolvedEvents` batch through the resolver into `NostrEmbed`.
Both layers validate the target against the original exact ID; a mismatched
target fails closed without a latest-revision fallback. These are mount-only
server inputs, not LiveProps or serialized Event snapshots. Live actions still
perform their own local lookup and do not accept a browser-supplied Event.

Pre-resolved local occurrences, including duplicate IDs, perform **zero**
additional Event lookups during page rendering. Non-batched/cold `e` rows retain
their resolver lookup and explicit Fetch/error behavior. A resolver-found Event
is also reused by the embed instead of being queried twice. Shared access,
warning, and exact Article projection/entitlement checks still run per preview;
this is Event lookup reuse, not an authorization cache. Policy and Article
queries are not batched by this change. LiveAction redesign, other bookmark
implementations, and global partial replacement remain outside this slice.

#### Validation

`tests/Unit/Twig/Components/Organisms/BookmarkItemResolverTest.php` uses the real
resolver, compiled Twig component tags, `NostrEmbed`, access policy, and shared
preview templates with mocked local repositories/network/persistence. Only
profile markup, icons, and the unrelated content filter are stubbed. Coverage
includes exact local identity, explicit missing-event fetching and relay
errors/success, DB errors, cold/local coordinates and relay hints, malformed
coordinates, unauthorized drafts, absent longform projections, suppression,
warnings, and profile/topic/removal parity.

Targeted Docker validation passes **230 PHPUnit tests / 1,610 assertions**
across this suite and the existing shared preview, policy, filter,
comment-context, deferred runtime, and API suites. PHP syntax, all four reused
Twig templates, and `lint:container` pass. This is not a live-browser signer,
Live Component HTTP hydration, live relay, worker, or end-to-end database test.
The existing LiveAction implementation is intentionally not redesigned here.

The follow-up also renders the actual compiled bookmarks-page body with real
resolver mounting, shared previews, and policy (only the unrelated layout is
replaced). It supplies the controller-shaped pre-resolved map and verifies that
it cannot bypass policy, exact-ID navigation, mixed `e`/`a`/`p`/`t` order and
duplicates, cold-event Fetch, DB errors, row/removal layout, original signed
tags/content and signer configuration, suppression, scoped events, missing
longform projections, public versus exclusive exact Article projections, and
metadata-free warnings. The component is registered
as static in this harness to omit Live HTTP transport; its real PHP class,
mount, and template are used. Existing shared component tests separately cover
exact Article entitlement and the full preview policy.

Follow-up Docker validation after restoring batch reuse passes
**86 PHPUnit tests / 618 assertions**:
28 resolver/page tests, 35 shared `NostrEmbed` tests, and 23 preview-access-policy
tests. The page, resolver, and shared embed pass Twig lint; the changed PHP
files pass syntax checks, and `lint:container` passes. Browser signer and Live
HTTP hydration remain unverified.

Query-count expectations cover 20 local occurrences across distinct and
duplicate IDs with no Event repository calls, mixed local/cold rows with only
the cold exact-ID lookup, and denied/warning/Article entitlement cases with no
Event requery. Mismatched batch entries and supplied `note`/`nevent` targets
cannot bypass exact identity checks.

## Files

- `src/Controller/Reader/BookmarksController.php` — dedup logic + API endpoints
- `assets/controllers/ui/bookmark_list_controller.js` — signed removal from the bookmarks page
- `assets/controllers/ui/card_bookmark_controller.js` — bookmark toggles on cards and article pages
- `templates/components/Molecules/ArticleSocialActions.html.twig` — article-page bookmark button
- `templates/pages/article.html.twig` — bookmark button placement
- `templates/pages/my-bookmarks.html.twig` — ordered bookmark rows using the resolver, including formerly pre-resolved exact events
- `templates/components/Organisms/BookmarkItemResolver.html.twig` — compact shared event/address previews with explicit missing-event Fetch and signed removal controls
- `tests/Unit/Twig/Components/Organisms/BookmarkItemResolverTest.php` — resolver action and real shared-component template regression coverage
- `translations/messages.{en,de,es,fr,it,sl}.yaml` — bookmark translations

## Card shortcuts and pending publication

### Overview

Article cards now include a dedicated bookmark shortcut button.

The shortcut publishes kind `10003` bookmark updates without requiring a page reload between actions.

The article social strip uses the same IndexedDB-backed publish flow.

### Frontend flow

- Template: `templates/components/Molecules/Card.html.twig`
- Stimulus controller: `assets/controllers/ui/card_bookmark_controller.js`
- Article-page template: `templates/components/Molecules/ArticleSocialActions.html.twig`
- Bookmark button is rendered for logged-in users only.
- Coordinate format used for bookmarks: `kind:pubkey:slug`

### Reliability strategy

The Stimulus controller keeps the latest bookmark state in IndexedDB:

- IndexedDB database: `newsroom-bookmarks`
- Store: `bookmark-events` (keyed by signer pubkey)
- Saved state includes tags, last signed event, publish status, retry counters, and last success timestamp.

When a user toggles a bookmark:

1. The controller starts from the last known bookmark snapshot in IndexedDB.
2. It adds/removes the target `a` tag coordinate locally.
3. It signs a fresh kind `10003` event.
4. It persists the signed event as `pending` in IndexedDB.
5. It publishes to `POST /api/bookmarks/publish`.

If publish fails, the signed event remains in IndexedDB and is retried with exponential backoff (capped), so retries remain idempotent and resume from the same stored signed payload.

### APIs used

- `GET /api/bookmarks/current` (`api_bookmarks_current`)
- `POST /api/bookmarks/publish` (`api_bookmarks_publish`)

### Styling

Card bookmark button styling lives in:

- `assets/styles/03-components/card.css`
