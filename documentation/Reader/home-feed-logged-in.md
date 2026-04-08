# Home Feed for Logged-In Users

## Overview

When a user is logged in, the homepage (`/`) shows a tabbed article feed instead of the static landing page. Anonymous users still see the landing page as before.

## Tabs

| Tab | Source | Description |
|-----|--------|-------------|
| **Articles** | Discussed + Follows + Interests (merged) | Combined, deduplicated feed from articles with comments, followed authors, and interest topics. Each item is tagged with its source(s). Default tab for logged-in users. |
| **Media** | Follows + Interests media events (kinds 20, 21, 22) | Non-NSFW media from followed authors and interest hashtags, merged and deduplicated, displayed in a masonry grid. |
| **Podcasts** | Follow pack (kind 39089) | Articles from npubs in the admin-configured podcast follow pack. |
| **News Bots** | Follow pack (kind 39089) | Articles from npubs in the admin-configured news bot follow pack. |

### Articles Feed Details

The "Articles" tab merges three article sources into one deduplicated, time-sorted list:

1. **Discussed** — articles that have comments (kind 1111), fetched via `findArticlesWithComments()`. Comment counts are preserved and displayed on cards.
2. **Follows** — articles from pubkeys the user follows (kind 3 follow list), fetched via `findLatestByPubkeys()`.
3. **Interests** — articles matching the user's interest tags (kind 10015), fetched via `findByTopics()`.

Articles are deduplicated by coordinate (`pubkey:slug`). If an article appears in multiple sources, it receives multiple source badges. The merged list is sorted by `createdAt` descending and limited to 60 items.

Source badges are displayed between the cover image and the title as uppercase labels: **Discussed**, **Follows**, **Interests**.

### Media Feed Details

The "Media" tab merges media events from two personalized sources:

1. **Follows** — media events from pubkeys the user follows (kind 3 follow list), fetched via `findNonNSFWMediaEventsByPubkeys()`.
2. **Interests** — media events matching the user's interest hashtags (kind 10015), fetched via `findMediaEventsByHashtags()` with NSFW filtering.

Supported media kinds:
- **NIP-68** picture events (kind 20)
- **NIP-71** video events (kinds 21, 22, 34235, 34236)

Events are deduplicated by event ID, sorted by `created_at` descending, and limited to 42 items. Both admin-muted and user-muted pubkeys are excluded. Rendered using the shared `partial/_masonry.html.twig` template.

### Previous Tabs (archived)

The following tabs were previously shown individually but are now combined into "Articles":
- **Latest** — removed from the logged-in home page
- **Discussed** — merged into Articles
- **Follows** — merged into Articles
- **Interests** — merged into Articles

The individual tab routes (`/home/tab/latest`, `/home/tab/discussed`, `/home/tab/follows`, `/home/tab/interests`) still work for backward compatibility.

## Architecture

### Routing

- `GET /` — renders `home.html.twig` (anonymous) or `home_authenticated.html.twig` (logged in)
- `GET /home/tab/{tab}` — returns a `<turbo-frame>` partial for the given tab (`articles`, `media`, `podcasts`, `newsbots`, plus legacy: `latest`, `follows`, `interests`, `discussed`, `foryou`)

### Controllers

- **`DefaultController::index()`** — detects login state and renders the appropriate template.
- **`HomeFeedController::tab()`** — dispatches to per-tab methods and returns Turbo Frame partials.

### Follow Pack System

Follow packs are Nostr events of kind **39089**. Each event has `p` tags listing member npubs and a `d` tag for identification.

The **`FollowPackSource`** entity maps a purpose (`podcasts` or `news_bots`) to a specific follow pack event coordinate (format: `kind:pubkey:d-tag`).

The **`FollowPackService`** resolves the coordinate to an `Event` entity in the database, extracts the `p` tags, and fetches articles from those pubkeys.

### Admin Interface

Route: `/admin/follow-packs`

The admin interface allows:
1. Viewing all kind 39089 events in the database
2. Assigning a follow pack coordinate to the Podcasts or News Bots purpose
3. Removing a source assignment

### Frontend

The tabbed interface uses a **Stimulus controller** (`content--home-tabs`) that:
1. Intercepts tab link clicks
2. Updates the active tab visual state immediately
3. Fetches the tab content via `fetch()` with Turbo Frame headers
4. Injects the response HTML into the `<turbo-frame id="home-tab-content">` element

The initial tab (Articles) is loaded via Turbo Frame's `lazy` loading attribute.

### CSS

Styles are in `assets/styles/04-pages/home-feed.css`. The tabs reuse the existing `.profile-tabs` and `.tab-link` CSS classes from the author profile. Source badges are styled in `assets/styles/03-components/source-badge.css`.

## Database

### New table: `follow_pack_source`

| Column | Type | Description |
|--------|------|-------------|
| `id` | SERIAL | Primary key |
| `purpose` | VARCHAR(50) | Unique. Enum value: `podcasts` or `news_bots` |
| `coordinate` | VARCHAR(500) | Nostr event coordinate: `kind:pubkey:d-tag` |
| `created_at` | TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | Last update timestamp |

Migration: `Version20260314120000`

### New enum in KindsEnum

`FOLLOW_PACK = 39089` — follow pack event kind.

## Translation Keys

All user-facing strings are under the `home_feed.*` namespace in `translations/messages.en.yaml`.

