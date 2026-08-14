# Navigation Layouts

The app uses three sidebar layouts so global discovery, private reading, and publishing workflows do not compete in one long menu.

## Layouts

| Layout | Use For | Nav Data |
|---|---|---|
| `layout.html.twig` | Public/discovery pages and the default shell. | `buildMainNav()` |
| `reading-nook-layout.html.twig` | Reading Nook pages: bookmarks, interests, reading lists, follow packs. | `buildReadingNookNav()` |
| `newsroom-layout.html.twig` | Authored/publishing pages: my content, magazines, media manager. | `buildNewsroomNav()` |

The layouts render the shared `SidebarNav` Twig component.

## Core Files

| File | Purpose |
|---|---|
| `src/Twig/Components/SidebarNav.php` | Component class for sidebar rendering. |
| `templates/components/SidebarNav.html.twig` | Sidebar template. |
| `src/Helper/NavigationBuilderTrait.php` | Builds main, Reading Nook, and Newsroom nav arrays. |
| `templates/reading-nook-layout.html.twig` | Reading Nook shell. |
| `templates/newsroom-layout.html.twig` | Publishing shell. |

## Controller Usage

```php
use App\Helper\NavigationBuilderTrait;

final class MyController extends AbstractController
{
    use NavigationBuilderTrait;

    public function page(): Response
    {
        return $this->render('my/page.html.twig', [
            'readingNookNav' => $this->buildReadingNookNav(),
        ]);
    }
}
```

Use `newsroomNav` with `buildNewsroomNav()` for Newsroom pages.

## Template Usage

```twig
{% extends 'reading-nook-layout.html.twig' %}

{% block body %}
    ...
{% endblock %}
```

## Rules

- Keep the global sidebar to top-level product areas.
- Put workflow-specific links in the local Reading Nook or Newsroom layout.
- Do not hard-code nav labels in templates; add translation keys.
- Keep route names stable so bookmarked URLs continue to work.