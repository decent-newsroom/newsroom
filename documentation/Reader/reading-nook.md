# Reading Nook

The shared lazy disclosure shell also exposes the existing owner-only Bookshelf
directory references at most once per Reading Nook page, without adding them to inventory
filters/counts or querying them until opened. `EVENT_WORKSPACE_EXPANSION_ENABLED`
defaults to `true`; setting it to `false` hides workspace disclosures and returns
404 from their fragment endpoints, including nested TOCs. Ordinary inventory and
Open/Manage routes remain available. See the [iteration plan](event-workspace-iteration-plan.md#shared-step-2-disclosure-shell-and-rollback)
for rollback and intentional Step 3+ deferrals.

On Reading Nook and My Content, the current browser tab remembers expanded
disclosures and scroll position as UI-only `sessionStorage` state, scoped to the
signed-in public key and workspace, and is not synchronized between tabs.
Disclosure IDs can contain event identifiers; event content and fetched
fragments are not cached. State for other accounts is cleared on workspace entry and logout;
at most four already-rendered disclosures are restored automatically. Nested
disclosures that have not been loaded are not discovered just to restore state.
Storage failures simply leave the page in its normal collapsed state.
Lazy workspace frames share at most four concurrent requests in a tab; queued
closed disclosures wait until reopened, and disconnecting a disclosure cancels
its pending requests. Explicit TOC discovery has its own four-request limit.

Reading Nook is a personalized workspace for navigating a user's own read-side Nostr collections from a single page. It merges bookmarks, topic lists, follow packs, reading lists, and local subscriptions into one searchable and filterable pool. [Subscriptions](subscriptions.md) are free for all supported sources, with a dedicated change feed and separate source management.

Profile subscriptions resolve their stored hex pubkey or npub through
the cached kind `0` profile metadata. Subscription rows prefer
`display_name`, then `name`, and retain the shortened identifier fallback when
no profile name is available.

## Overview

Reading Nook is designed for a comfortable "my library" experience:
- One route (`/reading-nook`) for owned read-side collections
- Search limited to owned content/events only
- Filtering by section, timespan, and tags
- Dedicated sidebar for quick navigation to each source page
- Authored content management stays in the Newsroom workspace (`/my-content`, `/my-magazines`)

Topic lists include both NIP-51 kind `10015` interests and kind `30015`
interest sets. Untitled kind `10015` entries are shown as `My interests`.
The Manage action for kind `30015` entries opens the editor for that exact
interest set (`/my-interests/set/{dTag}/edit`), while kind `10015` continues
to use the main `/my-interests` editor.

## UI Layout

- **Create & organize** shortcuts above the inventory open a new follow pack,
  a new reading list, the magazine wizard, or interest management. The magazine
  wizard can combine existing reading lists as sections without modifying the
  source lists. New reading-list and magazine actions start fresh drafts;
  Turbo hover prefetch is disabled for these draft-resetting links.
  The follow-pack shortcut uses `?new=1` to open an empty form while preserving
  the currently selected recommendation pack and access to existing packs.
- **Collection navigation** shows counts for all items and each collection type;
  search, timespan, and tag filters keep their current values when changing
  sections. Popular tags are also available beside the filters on every screen
  size.
- **Pagination** shows 25 authorized inventory rows per page; section counts
  and tag facets are calculated from the authorized inventory. Access checks
  run in bounded batches; an unavailable policy lookup produces an error
  rather than an incomplete or misleading empty inventory.
- **Inventory rows** use a single readable layout rather than a multi-column
  table, with a title, optional summary and tags, section, kind or subscription
  type, date, available counts, and an action menu for opening or managing the
  item. Kind `39089` follow packs, kinds `10003`/`30003` bookmarks, and kind
  `30040` publication indexes retain their separate lazy disclosures; title
  links and the Open/Manage menu retain their existing behavior.
- **Saved directory references** (the owner's Bookshelf "My Books" kind
  `30045` directory) appear in their own lazy "My Books directory" entry next
  to the first drive row on the page, or on unfiltered page 1 of the all/drives
  views when no drive is shown. This separate entry does not imply the
  directory is mounted in that drive. It appears at most once per page and is
  not included in inventory counts or filters.

Together with [My Content](../Newsroom/my-content-page.md), this inventory is
the starting point for the [expandable event workspace](event-workspace-iteration-plan.md).

### NKBIP-04 drives

Reading Nook inventories the current locally stored kind `30042` revision for
each owned drive coordinate, subject to access checks, and opens its ordered
kind `30045` directory references in a read-only mount. A direct exact-ID mount
can also display a readable drive by another author. Drives and directories by
others never grant editing or publishing rights. Every drive, directory, and
target is independently checked for suppression, tombstones, hidden coordinates,
reader mutes, scope and warning evidence before its metadata is shown.
Viewer-specific mount responses are private and not cached.

The mount preserves raw tag order, positions, duplicate occurrences, exact
version pins, and unresolved or invalid references. It reads local data only,
loads at most 50 occurrences per page, detects cycles along the current path,
and limits directory nesting to six levels. Directory URLs use signed traversal
state and revalidate each edge on every request. Missing and unauthorized targets
share the same unavailable status. The mount does not fetch relay data, inline
arbitrary event bodies, or offer target links to legacy routes that cannot
recheck access and preserve exact revisions. Supported, authorized locally stored
targets can instead be opened in an exact, read-only event view, using a signed
occurrence and rechecking the path, target identity, and visibility. Supported
kinds are `1`, `20`, `21`, `22`, `30023`, `30040`, and `30041`; longform
Articles additionally require a matching projection and entitlement. The view
uses tag-based presentation and escaped plain text, not rendered Article HTML,
and does not automatically load remote media. Restricted and unsupported
targets remain unavailable. See
[NKBIP-04](../NKBIP/04.md) for the signed drive and directory formats.

### Bookmark references

Owned kind `10003` bookmark lists and kind `30003` bookmark sets have a separate
lazy disclosure that displays up to 50 `e`, `a`, `p`, and `t` tag occurrences
per page. Source tag positions, order, and duplicates are retained. Valid event
IDs, profiles, and addressable-event coordinates are displayed as NIP-19
identifiers when supported; topics are shown as hashtags. Malformed references
remain visible with an invalid-reference label.

Each GET to `/reading-nook/bookmarks/{eventId}/references?page=1` requires
`ROLE_USER` and reads the exact source event only when it belongs to the current
user and has kind `10003` or `30003`. Author suppression and NIP-09 deletion are
checked before rendering; responses use `Cache-Control: private, no-store`.
The view resolves visible event/address targets through the shared DB-only
preview path below. It does not fetch events or profiles, expose the source
event body, or add contact-list (kind `3`) previews. Bookmark fourth slots are
never treated as version pins.

### Shared local target previews

Bookmark fragments and the Bookshelf directory fragment both use
`TargetPreviewResolver` and the escaped `event_workspace/_target_preview.html.twig`
partial. Only the visible page (at most 50 occurrences, including duplicate and
invalid rows) is eligible for target resolution. One batch query selects
metadata, not bodies/signatures: exact `e` IDs stay exact, unpinned `a` identities
use the latest local revision with lowest-ID tie-break, and directory `a[3]`
pins require a matching exact event and coordinate. Missing/mismatched pins
never fall back to latest.

Public kinds `30040`, `30041`, `1`, `20`, `21`, and `22` can show kind and a
bounded tag-derived title without Article rows or processed HTML. Every request
batch-checks target author suppression, NIP-09 deletion, hidden coordinates, and
the current reader's strict mutes. Policy failures propagate rather than
returning unfiltered metadata. Drafts/private/unknown kinds are restricted.
Article kind `30023` remains restricted until safe Article/member entitlement
can be established, including for Event-only targets; even owners/members get
no exception in this deliberately conservative slice.

Missing local targets, restricted previews, and mismatched/invalid pins have
explicit localized states with no target metadata. Sensitive titles are hidden;
no raw tags, outgoing references, descriptions, body text, or media URLs cross
the target-preview boundary. Titles are escaped, never rendered as HTML. Rows
have no target links or image elements. Profile/topic references remain unchanged.

Source occurrence order, identities, duplicates, invalid labels, pagination,
actions, routes, owner policies, private/no-store caching and the existing
rollback flag remain unchanged. No target network fetch, persistence, async
dispatch, body conversion, or recursive expansion is added. Normal Bookshelf
page refresh and book resolution remain separate and unchanged.

### Follow-pack members

Opening the disclosure lazily requests the pack's public `p` references as
npubs, 50 occurrences per page. Previous/Next links replace only that pack's
Turbo Frame. With JavaScript disabled, the Load members link opens the same
read-only page. Ordered duplicate occurrences remain separate; malformed
references show an invalid-reference label rather than disappearing.

Member pages are pinned to the exact signed event revision. Every request to
`/reading-nook/follow-packs/{eventId}/members?page=1` requires `ROLE_USER` and
queries that ID with the current user's pubkey and stored kind `39089`.
Browser-supplied owner/kind parameters cannot change access. Author suppression
and deletion tombstones are checked before rendering, and successful responses
use `Cache-Control: private, no-store`. Only public tags are shown; opaque event
content is not decrypted or exposed. A removed event returns not found.

This view uses `EventWorkspaceSourceAdapter` and the presentation factory's
`FollowPackMember` role. It does not resolve profiles, fetch events, write data,
or expand kind `3`, Bookshelf, My Content, or arbitrary `a`/`e` relationships.
The existing row member count is a deduplicated facet, not the number of ordered
occurrences on these pages.

### Publication-index table-of-contents

An eligible owned kind `30040` source can expose a separate table-of-contents
disclosure in a lazy Turbo Frame. The frame loads up to 50 `Content`-role `a`
reference occurrences per page from the source event's presentation. It
preserves tag positions and order, duplicate occurrences, malformed entries,
coordinate identity, version pins, and unresolved occurrences. A kind `30040`
reference can expand another locally stored kind `30040` index. Previous/Next
links replace only the frame; without JavaScript, the Load table of contents
link opens the same read-only page.

Each GET to
`/reading-nook/publication-indexes/{eventId}/toc?page=1` requires `ROLE_USER`
and re-queries that exact event ID for the current user's pubkey and kind
`30040`; browser-supplied owner or kind values cannot broaden access. Author
suppression and NIP-09 deletion are checked before rendering, and successful
responses use `Cache-Control: private, no-store`. Pagination bounds rendered
occurrences, not the in-memory scan of the selected source's tags.

Nested expansion resolves only locally stored kind `30040` events in one
batched lookup for the visible page. Unpinned references use the latest local
revision; pinned references resolve by exact event ID and must still match the
referenced kind, author, and `d` identifier. A missing or mismatched pin never
falls back to a different revision. Duplicate references remain independent
occurrences, and a repeated event on the current ancestry path is shown as a
cycle instead of recursively expanded. Nesting is capped at six index levels.
Other reference kinds and unresolved or invalid targets remain reference-only.

Target titles and summaries are exposed only after hidden-coordinate, reader
mute, author-suppression, and NIP-09 deletion checks. Sensitive target metadata
is gated. Initial TOC rendering does not load target bodies or start relay work.
Chapter bodies require a separate authorized lazy request; discovery of missing
unpinned publication addresses requires an explicit action. Traversal path and nested-page state are signed
with the application secret to preserve occurrence identity and prevent path
reset/tampering, but the signature is not authorization: every fragment
rechecks source access. Responses use `Cache-Control: private, no-store`.

The inventory's previous heuristic that treated any index with a kind `30040`
child as a magazine has been removed; these indexes now remain in Reading
Nook. This does not add My Content, generic note/`e` references, kind `3`, or
Bookshelf behavior.

## Architecture

### Data model

Reading Nook does not introduce new tables. It composes existing data sources:
- `Event` — user-owned NIP-51 and collection events (bookmarks, interests, follow packs, reading lists)
- `UpdateSubscription` — user-managed update source subscriptions (authors, publications, NIP-51 sets)

### Flow

1. User opens `/reading-nook`
2. Controller resolves current user pubkey
3. Current owned collection revisions and active local subscriptions are merged
4. Query filters (`q`, `section`, `timespan`, `tag`) are applied server-side
5. Counts and tag facets are calculated over the complete local inventory
6. Twig renders a 25-item page, retaining filters in Previous/Next links

### Key files

| File | Role |
|---|---|
| `src/Controller/Reader/ReadingNookController.php` | Route + owned-pool aggregation + filtering |
| `templates/reader/reading_nook/index.html.twig` | Reading Nook page layout and inventory rows |
| `src/Service/EventWorkspace/FollowPackMemberPage.php` | Pure ordered member pagination and npub conversion |
| `src/Twig/Components/Molecules/EventMemberDisclosure.php` | Static disclosure; no data lookup |
| `templates/reader/reading_nook/_follow_pack_members.html.twig` | Authorized, paginated member fragment |
| `src/Service/EventWorkspace/BookmarkReferencePage.php` | Ordered bookmark reference pagination and NIP-19 display identifiers |
| `src/Twig/Components/Molecules/EventBookmarkDisclosure.php` | Static bookmark disclosure |
| `templates/reader/reading_nook/_bookmark_references.html.twig` | Authorized, paginated bookmark fragment |
| `src/Service/EventWorkspace/PublicationTocPage.php` | Ordered TOC pages and batched local nested-index resolution |
| `src/Service/EventWorkspace/PublicationTocTraversalSigner.php` | Integrity-protects nested occurrence and pagination state |
| `src/Repository/EventRepository.php` | Batch local target lookup by coordinate or exact event ID |
| `assets/controllers/ui/event_workspace_state_controller.js` | Tab-local disclosure and scroll state |
| `assets/controllers/ui/workspace_fragment_controller.js` | Shared lazy-frame concurrency limit and cancellation |
| `src/Twig/Components/Molecules/EventTocDisclosure.php` | Static publication-index disclosure |
| `templates/reader/reading_nook/_publication_toc.html.twig` | Authorized, paginated TOC and nested-index fragment |
| `templates/reading-nook-layout.html.twig` | Reading Nook shell with local sidebar + page aside |
| `assets/styles/04-pages/reading-nook.css` | Page styling and sidebar layout |
| `assets/app.js` | Imports Reading Nook stylesheet |
| `src/Helper/NavigationBuilderTrait.php` | Builds the Reading Nook and main navigation entries |

## Configuration

`EVENT_WORKSPACE_EXPANSION_ENABLED` defaults to `true`; disabling it rolls
back the shipped workspace expansion controls and their fragment endpoints.
`EVENT_WORKSPACE_METRICS_ENABLED` defaults to `false` and enables local,
aggregate-only diagnostics described in
[event workspace performance](event-workspace-performance.md).

## Limitations / Known Issues

- Filtering is currently server-side and request-driven; there is no live incremental filtering.
- Search is limited to locally persisted owned data (as intended), so remote-unfetched events are not included.
- Subscription profile names require an authorized local kind `0` event;
  cached-only names lack signed event evidence for the visibility checks.
- Computing complete counts and facets scans the local current-revision inventory on each request; subscriptions and distinct facet/drive keys are retained in memory.
- Member and TOC pagination bound rendered occurrences and npub encoding, but the selected event's JSON tags are still loaded and scanned in memory by the shared presentation factory.
- Nested index expansion uses locally stored kind `30040` targets. Available kind `30041` chapters have separately authorized lazy previews, and missing unpinned publication addresses can be explicitly discovered asynchronously.
- Bookmark expansion retains unresolved references and uses bounded local target previews for supported kinds; it does not expand contact lists.
- Kind `3` follows and standalone kind `30045` directories are not inventory sections. Directories can be browsed through the Bookshelf disclosure or a readable drive.
- Locale files still contain placeholder translations for other non-English Reading Nook strings; the TOC disclosure labels are translated in all six enabled locales.

## Future Improvements

- Replace remaining placeholder locale entries with complete translations for all enabled Reading Nook locales (`en`, `de`, `es`, `fr`, `it`, and `sl`).
- Add source chips on each row (for example: bookmarks, interests, follow packs, reading lists) for faster scan/navigation.
- Add support for private/encrypted bookmarks in the owned pool once decryption/access strategy is defined for user sessions.
- Add "Own highlights" integration so a user's authored highlights are included as a first-class Reading Nook section.

## Related NIPs / NKBIPs

- [NIP-51](../NIP/51.md) — list-based user collections (bookmarks, interests, follow packs)
