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
  action menu for opening or managing the item. These rows do not currently
  expand into the contents of an event.
- **Contextual aside** shows totals, section links, and popular tags.

Together with [My Content](../Newsroom/my-content-page.md), this inventory is
the precursor for the proposed [expandable event workspace](event-workspace-iteration-plan.md).

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
- Kind `3` follows and kind `30045` directories are not included in this inventory. A kind `30040` containing a kind `30040` reference is excluded as a magazine index.
- Locale files currently contain placeholder translations for non-English Reading Nook strings.

## Future Improvements

- Replace placeholder locale entries with complete translations for `en`, `de`, `es`, `fr`, and `sl` Reading Nook keys.
- Add pagination for long result sets (global and/or per section), including persisted filter query params between pages.
- Add source chips on each row (for example: bookmarks, interests, follow packs, reading lists) for faster scan/navigation.
- Add support for private/encrypted bookmarks in the owned pool once decryption/access strategy is defined for user sessions.
- Add "Own highlights" integration so a user's authored highlights are included as a first-class Reading Nook section.

## Related NIPs / NKBIPs

- [NIP-51](../NIP/51.md) — list-based user collections (bookmarks, interests, follow packs)
