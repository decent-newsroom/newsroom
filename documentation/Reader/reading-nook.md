# Reading Nook

Reading Nook is a personalized workspace for navigating a user's own read-side Nostr collections from a single page. It merges bookmarks, topic lists, follow packs, reading lists, and update subscriptions into one searchable and filterable pool.

Author update subscriptions resolve their stored hex pubkey or npub through
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

- **Section tabs** show counts for all items and each collection type.
- **Filters** provide search, timespan, and tag controls.
- **Inventory rows** show the section, kind, date, and available counts, with an
  action menu for opening or managing the item. Kind `39089` follow packs also
  have a separate keyboard-accessible member disclosure; title links and the
  Open/Manage menu retain their existing behavior.
- **Contextual aside** shows totals, section links, and popular tags.

Together with [My Content](../Newsroom/my-content-page.md), this inventory is
the starting point for the [expandable event workspace](event-workspace-iteration-plan.md).

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
is gated. No target body, rendered HTML, media, or action is shown; no relay
work or persistence occurs. Traversal path and nested-page state are signed
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
3. Owned collection `Event` records and active update subscriptions are loaded
4. Items are normalized into one list with section metadata
5. Query filters (`q`, `section`, `timespan`, `tag`) are applied server-side
6. Twig template renders inventory rows and sidebar navigation

### Key files

| File | Role |
|---|---|
| `src/Controller/Reader/ReadingNookController.php` | Route + owned-pool aggregation + filtering |
| `templates/reader/reading_nook/index.html.twig` | Reading Nook page layout and inventory rows |
| `src/Service/EventWorkspace/FollowPackMemberPage.php` | Pure ordered member pagination and npub conversion |
| `src/Twig/Components/Molecules/EventMemberDisclosure.php` | Static disclosure; no data lookup |
| `templates/reader/reading_nook/_follow_pack_members.html.twig` | Authorized, paginated member fragment |
| `src/Service/EventWorkspace/PublicationTocPage.php` | Ordered TOC pages and batched local nested-index resolution |
| `src/Service/EventWorkspace/PublicationTocTraversalSigner.php` | Integrity-protects nested occurrence and pagination state |
| `src/Repository/EventRepository.php` | Batch local target lookup by coordinate or exact event ID |
| `src/Twig/Components/Molecules/EventTocDisclosure.php` | Static publication-index disclosure |
| `templates/reader/reading_nook/_publication_toc.html.twig` | Authorized, paginated TOC and nested-index fragment |
| `templates/reading-nook-layout.html.twig` | Reading Nook shell with local sidebar + page aside |
| `assets/styles/04-pages/reading-nook.css` | Page styling and sidebar layout |
| `assets/app.js` | Imports Reading Nook stylesheet |
| `src/Helper/NavigationBuilderTrait.php` | Builds the Reading Nook and main navigation entries |

## Configuration

No new environment variables or Symfony parameters are required.

## Limitations / Known Issues

- Filtering is currently server-side and request-driven; there is no live incremental filtering.
- Search is limited to locally persisted owned data (as intended), so remote-unfetched events are not included.
- The event query takes the latest 400 rows before revision deduplication and filtering, so older collections may be omitted and counts may be incomplete.
- Member and TOC pagination bound rendered occurrences and npub encoding, but the selected event's JSON tags are still loaded and scanned in memory by the shared presentation factory.
- Nested resolution supports locally stored kind `30040` targets only; it does not resolve chapters or other kinds, fetch remote events, or render child bodies.
- Kind `3` follows and kind `30045` directories are not included in this inventory.
- Locale files still contain placeholder translations for other non-English Reading Nook strings; the TOC disclosure labels are translated in all six enabled locales.

## Future Improvements

- Replace remaining placeholder locale entries with complete translations for all enabled Reading Nook locales (`en`, `de`, `es`, `fr`, `it`, and `sl`).
- Add pagination for long result sets (global and/or per section), including persisted filter query params between pages.
- Add source chips on each row (for example: bookmarks, interests, follow packs, reading lists) for faster scan/navigation.
- Add support for private/encrypted bookmarks in the owned pool once decryption/access strategy is defined for user sessions.
- Add "Own highlights" integration so a user's authored highlights are included as a first-class Reading Nook section.

## Related NIPs / NKBIPs

- [NIP-51](../NIP/51.md) — list-based user collections (bookmarks, interests, follow packs)
