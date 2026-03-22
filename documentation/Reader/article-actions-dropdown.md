# Article Actions Dropdown

## Overview

The article actions dropdown consolidates secondary article actions (share, bookmark, broadcast, highlights) into a single kebab menu (⋮) button, keeping the article action bar clean and scalable for future actions.

**Prominent standalone buttons** (kept separate):
- **Zap** — Lightning payment, high-visibility action
- **Reading List** — Content curation, frequently used

**Consolidated into dropdown:**
- **Copy Link** — copies the canonical newsroom URL
- **Copy Nostr Address** — copies the naddr-encoded identifier
- **Bookmark** — adds/removes the article from kind 10003 bookmarks
- **Broadcast to Relays** — re-publishes the article event to the user's relays
- **Highlights** — toggles the highlights sidebar (if highlights exist)

## Architecture

### Twig Component

**PHP:** `src/Twig/Components/Molecules/ArticleActionsDropdown.php`
**Template:** `templates/components/Molecules/ArticleActionsDropdown.html.twig`

Props:
| Prop | Type | Description |
|------|------|-------------|
| `article` | `Article` | The article entity |
| `coordinate` | `string` | Nostr coordinate (`30023:<pubkey>:<slug>`) |
| `canonicalUrl` | `string` | Canonical URL for copy-link |
| `naddrEncoded` | `string` | naddr-encoded identifier |
| `isProtected` | `bool` | Whether article has `-` (protected) tag |
| `highlightCount` | `int` | Number of highlights |
| `relays` | `?array` | User's write relays (null if anon) |

Usage:
```twig
<twig:Molecules:ArticleActionsDropdown
    :article="article"
    coordinate="30023:{{ article.pubkey }}:{{ article.slug }}"
    canonicalUrl="{{ canonical }}"
    naddrEncoded="{{ article|naddrEncode }}"
    :isProtected="isProtected"
    :highlightCount="highlights is defined ? highlights|length : 0"
    :relays="userRelays" />
```

### Stimulus Controller

**File:** `assets/controllers/ui/article_actions_dropdown_controller.js`
**Identifier:** `ui--article-actions-dropdown`

Values:
| Value | Type | Description |
|-------|------|-------------|
| `coordinate` | String | Article coordinate for bookmarking |
| `bookmarkFetchUrl` | String | `GET /api/bookmarks/current` |
| `bookmarkPublishUrl` | String | `POST /api/bookmarks/publish` |

Targets:
| Target | Purpose |
|--------|---------|
| `trigger` | Kebab menu button |
| `menu` | Dropdown menu container |
| `status` | Status feedback text |
| `bookmarkItem` | Bookmark dropdown item (for active styling) |
| `bookmarkIcon` | Bookmark SVG icon (fill toggled) |
| `bookmarkLabel` | Bookmark text label |
| `broadcastItem` | Broadcast dropdown item |

Actions:
| Action | Description |
|--------|-------------|
| `toggle` | Open/close dropdown |
| `copy` | Copy text to clipboard (reads `data-copy` attribute) |
| `toggleBookmark` | Sign and publish kind 10003 event |
| `broadcast` | POST to `/api/broadcast-article` |
| `toggleHighlights` | Delegates to the existing `ui--highlights-toggle` controller |

### Styles

**File:** `assets/styles/03-components/article-actions-dropdown.css`

Key classes:
- `.article-actions-dropdown` — wrapper with inline-flex layout
- `.article-actions-trigger` — square kebab button, no caret
- `.dropdown-item--active` — accent color for bookmarked state
- `.article-actions-status--{info,success,error}` — feedback text colors

## Removed Components

The following components were deleted as they are fully superseded by this dropdown:

- `src/Twig/Components/Molecules/BookmarkButton.php` + `templates/components/Molecules/BookmarkButton.html.twig`
- `assets/controllers/nostr/nostr_bookmark_controller.js`
- `assets/styles/03-components/bookmark-button.css`
- `src/Twig/Components/Molecules/BroadcastButton.php` + `templates/components/Molecules/BroadcastButton.html.twig`
- `assets/controllers/ui/article_broadcast_controller.js`

**Kept** (still used by `MagazineHero`):
- `assets/controllers/utility/share_dropdown_controller.js`

## Files

### New
- `src/Twig/Components/Molecules/ArticleActionsDropdown.php`
- `templates/components/Molecules/ArticleActionsDropdown.html.twig`
- `assets/controllers/ui/article_actions_dropdown_controller.js`
- `assets/styles/03-components/article-actions-dropdown.css`

### Modified
- `templates/pages/article.html.twig` — replaced individual actions with dropdown
- `assets/app.js` — added CSS import
- `translations/messages.{en,de,es,fr,sl}.yaml` — added `articleActions.*` keys
- `CHANGELOG.md` — feature entry

