# Reading Nook

Reading Nook is a personalized workspace for navigating a user's own read-side Nostr collections from a single page. It merges bookmarks, topic lists, follow packs, reading lists, and update subscriptions into one searchable and filterable pool.

Author update subscriptions resolve their stored hex pubkey or npub through
the cached kind `0` profile metadata. Subscription cards prefer
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

The June 2026 refresh reshaped Reading Nook into a denser but calmer dashboard:
- **Overview strip** — top stat cards summarize total items, visible results, section count, tag coverage, and active filters.
- **Filter workbench** — search, section, timespan, and tag controls now live in a responsive panel with clearer hierarchy and larger targets.
- **Grouped collection cards** — each section renders as its own anchored block with clearer headings, stronger metadata chips, and action buttons for open/manage flows.
- **Right sidebar companion** — desktop aside now shows a compact snapshot, quick-jump links to populated sections, and popular tag shortcuts for fast refinement.

## Architecture

### Data model

Reading Nook does not introduce new tables. It composes existing data sources:
- `Event` — user-owned NIP-51 and collection events (bookmarks, interests, follow packs, reading lists)
- `UpdateSubscription` — user-managed update source subscriptions (authors, publications, NIP-51 sets)

### Flow

1. User opens `/reading-nook`
2. Controller resolves current user pubkey
3. Owned pool is built from read-side collection `Event` records only
4. Items are normalized into one list with section metadata
5. Query filters (`q`, `section`, `timespan`, `tag`) are applied server-side
6. Twig template renders grouped results and sidebar navigation

### Key files

| File | Role |
|---|---|
| `src/Controller/Reader/ReadingNookController.php` | Route + owned-pool aggregation + filtering |
| `templates/reader/reading_nook/index.html.twig` | Reading Nook page layout and grouped rendering |
| `templates/reading-nook-layout.html.twig` | Reading Nook shell with local sidebar + page aside |
| `assets/styles/04-pages/reading-nook.css` | Page styling and sidebar layout |
| `assets/app.js` | Imports Reading Nook stylesheet |
| `templates/layout.html.twig` | Adds Reading Nook link to left navigation |

## Configuration

No new environment variables or Symfony parameters are required.

## Limitations / Known Issues

- Filtering is currently server-side and request-driven; there is no live incremental filtering.
- Search is limited to locally persisted owned data (as intended), so remote-unfetched events are not included.
- Locale files currently contain placeholder translations for non-English Reading Nook strings.

## Future Improvements

- Replace placeholder locale entries with complete translations for `en`, `de`, `es`, `fr`, and `sl` Reading Nook keys.
- Add pagination for long result sets (global and/or per section), including persisted filter query params between pages.
- Add source chips on each card (for example: bookmarks, interests, follow packs, reading lists) for faster scan/navigation.
- Add support for private/encrypted bookmarks in the owned pool once decryption/access strategy is defined for user sessions.
- Add "Own highlights" integration so a user's authored highlights are included as a first-class Reading Nook section.

## Related NIPs / NKBIPs

- [NIP-51](../NIP/NIP-51.md) — list-based user collections (bookmarks, interests, follow packs)
