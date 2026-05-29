# Discover Page Tabs

## Overview

The discover page (`/discover`) features four tabbed sections for content exploration:

1. **Articles** — latest articles stream
2. **Activity** — merged feed of highlights (kind `9802`) and comments (kind `1111`) that reference long-form articles
3. **Editorial** — magazines, follow packs, and curated collections, filtered to publishers with `ROLE_EDITOR`
4. **Featured writers** — latest articles from users with `ROLE_FEATURED_WRITER` (same source logic as `/featured-articles`)

Tab selection is persisted in `localStorage.discover-tab`.

## Architecture

### Controller Data Sources

`DefaultController::discover()` aggregates data for all four tabs:

- **Articles**
  - Redis view store fast path (`fetchLatestArticles()`)
  - Database/search fallback when Redis view is missing
  - Existing exclusion/mute logic remains in place

- **Activity**
  - Highlights from Redis view store (`fetchLatestHighlights()`), with database fallback
  - Comments loaded from `event` table where `kind = 1111`
  - Only comments with an `a` tag referencing kind `30023` or `30024` are included
  - Highlights + comments are merged and sorted by `created_at` DESC

- **Editorial**
  - Built from magazines (`30040`), follow packs (`39089`), and curation sets (`30004/30005/30006`)
  - Restricted to events authored by users with `ROLE_EDITOR`
  - Items are deduplicated by `pubkey:slug` and sorted newest-first

- **Featured writers**
  - Uses shared helper feed logic with `/featured-articles`
  - Source users: `ROLE_FEATURED_WRITER`
  - Output: latest 50 articles + mapped author metadata

### Template Structure

`templates/pages/discover.html.twig` includes:

- Tab navigation: Articles, Activity, Editorial, Featured writers
- Panel rendering:
  - **Articles**: `CardList`
  - **Activity**: type-aware rendering
    - `highlight` items use `templates/partial/_highlight_feed_card.html.twig`
    - `comment` items render compact cards with author, markdown content, and article link
  - **Editorial**: card list with kind-based routing/badges
  - **Featured writers**: `CardList`

### Stimulus Controller

`assets/controllers/content/discover_tabs_controller.js`:

- Handles tab switching by toggling `.active` on tab/panel targets
- Saves selected tab to `localStorage.discover-tab`
- Restores the saved tab on connect (fallback: `articles`)

### Styling

Discover tab behavior reuses existing shared tab/panel styles (`.profile-tabs`, `.tab-link`, `.settings-panel`) and discover page styles in `assets/styles/04-pages/discover.css`.

## Rendering Details

### Articles Tab

Shows standard article cards with author metadata.

### Activity Tab

Merged feed sorted by `created_at` DESC:

- **Highlight item** (kind `9802`):
  - author, timestamp, highlighted/context text, source link
- **Comment item** (kind `1111` with long-form `a` reference):
  - author, timestamp, markdown-rendered comment, "view article" link

### Editorial Tab

Shows:

- magazines (`30040`)
- follow packs (`39089`)
- curation sets (`30004/30005/30006`)

Only if the item's author has `ROLE_EDITOR`.

### Featured Writers Tab

Shows latest articles from `ROLE_FEATURED_WRITER` users via the same backend helper used by `/featured-articles`.

## Translations

Discover-related keys include:

- `discover.articles`
- `discover.activity`
- `discover.noActivity`
- `discover.commented`
- `discover.viewArticle`
- `discover.editorial`
- `discover.noEditorial`
- `discover.featuredWriters`
- `discover.noFeaturedWritersArticles`

Locales: `en`, `de`, `es`, `fr`, `it`, `sl`.

## Performance Notes

- **Articles**: unchanged Redis-first flow with fallback
- **Activity**:
  - highlights benefit from Redis view cache
  - comments currently query recent `kind 1111` events directly
- **Editorial**:
  - database/graph sourced, limited to recent result sets
  - additional role-filter pass against editor pubkeys
- **Featured writers**: role-filtered user set + latest 50 articles

## UX Notes

- Tabs switch instantly without reload
- User's last selected tab persists via localStorage
- Mobile sticky tab offset respects header height
- Empty states are shown per tab when no content exists

## Possible Follow-ups

- Optional pagination for Editorial and Activity feeds
- Optional Redis view/cache for Editorial aggregation
- Optional precomputed/comment-enriched Activity view model

