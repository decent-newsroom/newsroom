# Skill: Create a Twig Live Component

Use this skill to add a new server-rendered interactive component. Components follow **Atomic Design** and live in `src/Twig/Components/{Atoms|Molecules|Organisms}/`.

---

## Choosing the tier

| Tier | When to use | Examples |
|---|---|---|
| **Atom** | Single self-contained UI element, no composition | `Alert`, `Button`, `Pagination` |
| **Molecule** | Composes atoms, wraps a single domain concept | `Card`, `ZapButton`, `UserFromNpub` |
| **Organism** | Full section, may own async actions/data loading | `Comments`, `CardList`, `ArticleFromCoordinate` |

---

## 1. Create the PHP class

File: `src/Twig/Components/{Tier}/YourComponent.php`

```php
<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
// Use a namespaced name for Organisms: #[AsLiveComponent('Organisms:YourComponent')]
final class YourComponent
{
    use DefaultActionTrait;

    // Read-only props passed from the parent template
    #[LiveProp(writable: false)]
    public string $someId = '';

    // Writable props — the browser/JS can update these
    #[LiveProp(writable: true)]
    public bool $open = false;

    // Computed / derived state (not a LiveProp — recalculated on every render)
    public array $items = [];

    public function __construct(
        private readonly SomeDependency $dependency,
    ) {}

    /**
     * Called once when the component first mounts.
     * Use mount() to initialise non-LiveProp state from props.
     */
    public function mount(string $someId): void
    {
        $this->someId = $someId;
        $this->items = $this->dependency->getItems($someId);
    }

    #[LiveAction]
    public function toggle(): void
    {
        $this->open = !$this->open;
    }
}
```

### Key rules

- **`#[LiveProp(writable: false)]`** — initial data from the server, never mutated by the browser.
- **`#[LiveProp(writable: true)]`** — UI state the browser may change (form inputs, toggles).
- **No `#[LiveProp]`** — computed values; set them in `mount()` or the action methods; they are re-derived each render.
- **Nullable `mount()` params** — always accept nullable for props that might not be set: `public function mount(?string $ident = null)`.

---

## 2. Create the Twig template

File: `templates/components/{Tier}/YourComponent.html.twig`

```twig
<div {{ attributes }}>
    {% if open %}
        <p>Open!</p>
    {% else %}
        <button
            data-action="live#action"
            data-live-action-param="toggle"
        >Open</button>
    {% endif %}
</div>
```

### Style rules

- **No inline styles** — all CSS goes in `assets/styles/`.
- **No shading, no rounded edges** (project-wide convention).
- All user-facing strings via `{{ 'key'|trans }}`.

---

## 3. Add CSS (if needed)

Add styles in the appropriate layer under `assets/styles/`:

| Layer | When |
|---|---|
| `03-components/` | Component-specific styles |
| `02-layout/` | Layout / positioning changes |
| `04-pages/` | Page-specific overrides |

Do **not** put styles inline in the template.

---

## 4. Embed the component in a parent template

```twig
{{ component('Molecules:YourComponent', { someId: article.id }) }}
```

For Organisms with a namespaced name:

```twig
{{ component('Organisms:YourComponent', { someId: article.id }) }}
```

---

## 5. Add translations

Add the key to every locale file in `translations/messages.{locale}.yaml` (en, de, es, fr, sl).

---

## Checklist

- [ ] PHP class in correct tier directory with `#[AsLiveComponent]`
- [ ] Twig template in `templates/components/{Tier}/`
- [ ] CSS in `assets/styles/03-components/` (no inline styles)
- [ ] All user-facing strings use `|trans`
- [ ] Translation keys added to all 5 locale files
- [ ] Component embedded correctly in parent template(s)
- [ ] `CHANGELOG.md` entry added

