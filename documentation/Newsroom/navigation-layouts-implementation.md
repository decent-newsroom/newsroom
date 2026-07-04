# Navigation Layouts: Implementation Guide

This document describes the new sidebar architecture introduced to support the Navigation Refactor Plan. The system provides three separate layouts for three areas of the application, with a reusable sidebar component and helper trait to keep nav rendering DRY.

## Architecture

### Three Layouts

| Layout | Purpose | Sidebar shows | Use case |
|---|---|---|---|
| `layout.html.twig` | Global main navigation | Top-level product areas only | Default; public pages, main discovery | 
| `reading-nook-layout.html.twig` | Reading Nook hub | Reading/collecting subsections | `/reading-nook` and sub-pages |
| `newsroom-layout.html.twig` | Newsroom (publishing) hub | Authored content subsections | `/my-content`, `/my-magazines`, etc. |

### Reusable Components

- **`SidebarNav` component** (`src/Twig/Components/SidebarNav.php`, `templates/components/SidebarNav.html.twig`)  
  Renders a nav structure from a data config. Sections, items, back link, and optional footer component are all configurable.

- **`NavigationBuilderTrait`** (`src/Helper/NavigationBuilderTrait.php`)  
  Trait providing three methods to build nav structures:
  - `buildMainNav()` — main global nav
  - `buildReadingNookNav()` — Reading Nook local nav
  - `buildNewsroomNav()` — Newsroom local nav

### Overall Structure

Each layout extends `base.html.twig` and provides:
- A left sidebar (`<div class="aside-wrapper">`)
- A main content area (`<main>`)
- A right sidebar / aside (`<aside id="rightNav">`)
- A back-to-top button

## How to Use

### Using the Main Layout (Global)

The default and most common layout for public/discovery pages.

```twig
{% extends 'layout.html.twig' %}

{% block body %}
    {# Your page content here #}
{% endblock %}

{% block aside %}
    {# Optional: override the right sidebar #}
{% endblock %}
```

Automatically shows: Articles, Topics, Highlights, Newsstand, and (if logged in) Reading Nook, Newsroom, and Create links.

### Using Reading Nook Layout

For pages inside the Reading Nook hub (bookmarks, interests, reading lists, follow packs).

```twig
{% extends 'reading-nook-layout.html.twig' %}

{% block body %}
    {# Your page content here #}
{% endblock %}

{% block aside %}
    {# Optional: override the right sidebar with Reading Nook context #}
{% endblock %}
```

Must define in controller:

```php
public function myPage(): Response
{
    return $this->render('reading-nook-page.html.twig', [
        'readingNookNav' => $this->buildReadingNookNav(),
        // ... other data
    ]);
}
```

The layout automatically provides:
- Overview
- Bookmarks / Interests (Saved section)
- Reading Lists / Follow Packs (Collections section)
- Back to Main Newsroom link
- UserMenu footer

### Using Newsroom Layout

For pages inside the Newsroom hub (my content, magazines, media manager).

```twig
{% extends 'newsroom-layout.html.twig' %}

{% block body %}
    {# Your page content here #}
{% endblock %}

{% block aside %}
    {# Optional: override the right sidebar with Newsroom context #}
{% endblock %}
```

Must define in controller:

```php
// Use the trait
use App\Helper\NavigationBuilderTrait;

class MyNewsroomController extends AbstractController
{
    use NavigationBuilderTrait;

    public function myContent(): Response
    {
        return $this->render('my-content.html.twig', [
            'newsroomNav' => $this->buildNewsroomNav(),
            // ... other data
        ]);
    }
}
```

The layout automatically provides:
- Overview
- Drafts / Published (Articles section)
- Magazines / Reading Lists (Publications section)
- Media section
- Back to Discover link
- UserMenu footer

## Adding the NavigationBuilderTrait to Controllers

To use the helper methods, add the trait to your controller:

### Reading Nook Controller

```php
namespace App\Controller\Reader;

use App\Helper\NavigationBuilderTrait;

class ReadingNookController extends AbstractController
{
    use NavigationBuilderTrait;
    
    #[Route('/reading-nook', name: 'reading_nook')]
    public function index(): Response
    {
        return $this->render('reader/reading_nook/index.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            // ... existing data
        ]);
    }
}
```

### Newsroom Controllers

```php
namespace App\Controller\Newsroom;

use App\Helper\NavigationBuilderTrait;

class MyContentController extends AbstractController
{
    use NavigationBuilderTrait;
    
    #[Route('/my-content', name: 'my_content')]
    public function index(): Response
    {
        return $this->render('my_content/index.html.twig', [
            'newsroomNav' => $this->buildNewsroomNav(),
            // ... existing data
        ]);
    }
}

class ReadingListController extends AbstractController
{
    use NavigationBuilderTrait;
    
    #[Route('/reading-list', name: 'reading_list_index')]
    public function index(): Response
    {
        return $this->render('reading_list/index.html.twig', [
            'newsroomNav' => $this->buildNewsroomNav(),
            // ... existing data
        ]);
    }
}
```

## Translation Keys Required

Add these to `translations/messages.{locale}.yaml`:

### Global Navigation

```yaml
nav:
  publications: 'Publications'
  personal: 'Personal'
  readingNook: 'Reading Nook'
  newsroom: 'Newsroom'
  newMagazine: 'New Magazine'
  newReadingList: 'New Reading List'
  newArticle: 'New Article'
  backToMainNewsroom: 'Back to Main Newsroom'
  backToDiscover: 'Back to Discover'
  discoverMoreContent: 'Discover more content'
  browseArticles: 'Browse Articles'
  publishYourContent: 'Publish your content'
  createArticle: 'Create Article'
```

### Reading Nook Navigation

```yaml
reading_nook:
  nav:
    overview: 'Overview'
    all_items: 'All Items'
    saved: 'Saved'
    bookmarks: 'Bookmarks'
    interests: 'Interests'
    collections: 'Collections'
    reading_lists: 'Reading Lists'
    follow_packs: 'Follow Packs'
```

### Newsroom Navigation

```yaml
newsroom:
  nav:
    overview: 'Overview'
    my_content: 'My Content'
    articles: 'Articles'
    drafts: 'Drafts'
    published: 'Published'
    publications: 'Publications'
    magazines: 'Magazines'
    reading_lists: 'Reading Lists'
    media: 'Media'
    media_manager: 'Media Manager'
```

## Migration Path

### Phase 1 — Use new layouts for existing pages

No new functionality; just update controllers to use the appropriate layout and pass nav data:

1. Update `ReadingNookController` to use `reading-nook-layout.html.twig`
2. Update `MyContentController` and `ReadingListController` to use `newsroom-layout.html.twig`
3. Update all Newsroom controllers to use the trait and pass nav data
4. Update primary templates (`reading-nook/index.html.twig`, `my_content/index.html.twig`, etc.)

**Phase 1 decision:** keep multi-step wizard flows (`mag_wizard_*`, `read_wizard_*`) on the standard `layout.html.twig` for now. These are focused, linear workflows where persistent local sidebar navigation can distract from completion. The newsroom sidebar is used on hub/index pages.

### Phase 2 — Simplify the main layout

Already done in this step; the global `layout.html.twig` is now simplified and does not include individual "my ..." links.

### Phase 3 — Archive old routes

Once Reading Nook and Newsroom are stable, review controller structure:
- Decide whether `my_content`, `my_bookmarks`, etc. need separate landing pages or can be entry points within the hub pages
- Consider consolidating redundant list pages
- Update routes to make the hub pages the primary entry point

## Testing the Layout Changes

### Quick tests to verify layout rendering

```bash
# Test main layout (global)
docker compose exec php bin/console route:list | grep discover

# Verify templates exist
ls -la templates/{layout.html.twig,reading-nook-layout.html.twig,newsroom-layout.html.twig,components/SidebarNav.html.twig}

# Verify trait exists
ls -la src/Helper/NavigationBuilderTrait.php

# Verify components exist
ls -la src/Twig/Components/SidebarNav.php
```

### Render a page with the new layout

Once a controller is updated to use `reading-nook-layout.html.twig` and pass `readingNookNav` data, the Reading Nook sidebar should appear on that page.

Example: Update `ReadingNookController`

```php
use App\Helper\NavigationBuilderTrait;

class ReadingNookController extends AbstractController
{
    use NavigationBuilderTrait;

    #[Route('/reading-nook', name: 'reading_nook')]
    public function index(/*...*/): Response
    {
        return $this->render('reader/reading_nook/index.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
            // ... rest of data
        ]);
    }
}
```

And in `templates/reader/reading_nook/index.html.twig`, change the first line to:

```twig
{% extends 'reading-nook-layout.html.twig' %}
```

Then visit `/reading-nook` and verify the sidebar shows the Reading Nook structure.

## Files Created/Modified

### New Files
- `src/Twig/Components/SidebarNav.php` — Sidebar nav component class
- `templates/components/SidebarNav.html.twig` — Sidebar nav template
- `src/Helper/NavigationBuilderTrait.php` — Navigation builder trait
- `templates/reading-nook-layout.html.twig` — Reading Nook layout
- `templates/newsroom-layout.html.twig` — Newsroom layout

### Modified Files
- `templates/layout.html.twig` — Simplified main global navigation

## Known Limitations / TODOs

- **Translations**: Nav labels use i18n keys; all keys must be added to all locale files for full functionality
- **Styling**: The `.sidebar-nav` class and related styles should match existing `.nav-section` and `.user-nav` styles; review `assets/styles/02-layout/layout.css`
- **Breadcrumbs**: Back links in the Reading Nook and Newsroom layouts currently point to fixed routes; could be enhanced with a `request.referrer` fallback
- **User menu location**: The `UserMenu` component currently appears at the end of all sidebars; if that position needs adjustment per layout, override the `footerComponent` parameter or move it inside `{% block aside %}`

## Next Steps

See `documentation/Newsroom/newsroom-navigation-refactor-plan.md` for the full refactor plan and Phase 2+ enhancements.

