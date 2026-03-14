# My Content Page & Editor Sidebar Update

## Summary

Removed reading lists from the editor sidebar to reduce clutter, and created a new **My Content** page (`/my-content`) that provides a genuine **filesystem browser** view of the user's articles, drafts, and reading lists — styled after macOS Finder list-view / VS Code file explorer.

## Changes

### Editor Sidebar (simplified)
- **Template**: `templates/editor/panels/_articlelist.html.twig` — removed the entire reading-list `<details>/<summary>` expandable section. The sidebar now only shows:
  - A "Manage all content →" link to the new My Content page
  - Published articles (with "A" badge)
  - Drafts (with "D" badge)
- **Controller**: `src/Controller/Editor/EditorController.php` — removed `$readingLists` variable and `buildAndCacheUserReadingLists()` calls from both `newArticle()` and `previewArticle()` methods. Removed unused `RedisViewStore` parameter from those methods.
- **Layout**: `templates/editor/layout.html.twig` — removed `readingLists` from the template include's `with` block.

### My Content Page (new) — Filesystem Browser
- **Route**: `GET /my-content` (name: `my_content`, requires `ROLE_USER`)
- **Controller**: `src/Controller/Newsroom/MyContentController.php`
  - Fetches published articles (kind 30023), collapsed by slug
  - Fetches drafts (kind 30024), collapsed by slug
  - Fetches reading lists (kind 30040) and curation sets (kinds 30004, 30005, 30006)
- **Template**: `templates/my_content/index.html.twig` — genuine filesystem layout:
  - **Toolbar**: breadcrumb path (`~/my-content`) + "New Article" / "New List" buttons
  - **Column headers**: Name, Date Modified, Items, Kind
  - **Three collapsible folders** (`<details>`):
    - `📁 drafts/` — draft articles
    - `📁 articles/` — published articles
    - `📁 reading-lists/` — reading lists & curation sets (30040, 30004, 30005, 30006)
  - **File rows**: emoji icons per type, clickable names, inline action buttons (↗ view, ✎ compose, ⧉ copy coordinate)
  - **Status bar**: item counts
- **CSS**: `assets/styles/04-pages/my-content.css`
  - Monospace font (SF Mono / Cascadia Code / Fira Code / Consolas)
  - 4-column grid rows (Name / Date / Items / Kind)
  - Dark-themed using existing CSS variables
  - CSS-only chevrons for folder expand/collapse
  - Hover highlight on file rows
  - Responsive: collapses to 2 columns on mobile

### Navigation
- Added "My Content" link to the left navigation sidebar (`templates/layout.html.twig`) under the Newsroom section
- Added `.articlelist-manage-link` CSS to `assets/styles/editor-layout.css` for the sidebar link

## File List
| File | Action |
|------|--------|
| `src/Controller/Editor/EditorController.php` | Modified — removed reading list data fetching |
| `src/Controller/Newsroom/MyContentController.php` | **New** — My Content page controller |
| `templates/editor/panels/_articlelist.html.twig` | Modified — removed reading list section |
| `templates/editor/layout.html.twig` | Modified — removed `readingLists` from include |
| `templates/layout.html.twig` | Modified — added "My Content" nav link |
| `templates/my_content/index.html.twig` | **New** — Filesystem browser template |
| `assets/styles/04-pages/my-content.css` | **New** — Filesystem browser styles |
| `assets/styles/editor-layout.css` | Modified — added `.articlelist-manage-link` styles |
| `assets/app.js` | Modified — imported `my-content.css` |
| `CHANGELOG.md` | Modified — added feature entries |

