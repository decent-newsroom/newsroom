# Segmented Sidebar Navigation

## Overview

The left sidebar navigation has been reorganised into three labelled segments separated by dividers. This provides a clearer information hierarchy and groups related pages by intent.

## Segments

### Discover
Public discovery pages – no login required.

| Item        | Route              |
|-------------|--------------------|
| Explore     | `discover`         |
| Topics      | `forum`            |
| Multimedia  | `media-discovery`  |
| Highlights  | `highlights`       |

### Newsroom
Content management – some items require authentication.

| Item              | Route / Notes                                  |
|-------------------|------------------------------------------------|
| Newsstand         | `newsstand`                                    |
| Collections       | `lists` (reading lists index)                  |
| Bookmarks         | `author-profile-tab` (bookmarks tab, logged-in)|
| My Magazines      | `author-profile-tab` (overview tab, logged-in) |
| My Reading Lists  | `reading_list_index` (logged-in)               |
| My Interests      | Placeholder (`#`) – page not yet implemented   |

### Create
Content creation actions.

| Item             | Route                |
|------------------|----------------------|
| New Magazine     | `mag_wizard_setup`   |
| New Reading List | `reading_list_index` |
| New Article      | `editor-create`      |

## Files Changed

- `templates/layout.html.twig` – restructured `<nav>` into `.nav-section` blocks.
- `assets/styles/02-layout/layout.css` – added `.nav-section`, `.nav-section__label` styles with border dividers.

## TODO

- Create a dedicated **My Interests** page based on the interests event kind.
- Consider a dedicated **My Magazines** page (currently links to the author profile overview tab).
- Consider a dedicated **Collections** page separate from the reading lists index.

