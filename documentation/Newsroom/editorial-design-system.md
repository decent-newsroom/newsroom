# Editorial Design System

Decent Newsroom uses a flat editorial interface designed for reading, discovery, and publishing. The system replaces page-specific visual conventions with shared tokens, a single application shell, and reusable navigation, tab, button, form, and story-card patterns.

## Overview

The design system solves three recurring problems:

- competing color and typography token definitions;
- global element selectors that made page navigation and application sidebars affect each other;
- inconsistent rounded, shadowed, or brightly colored components across product areas.

The primary audiences are readers, writers, and administrators. The interface deliberately resembles an editorial workbench: typography, spacing, and rules establish hierarchy. Containers have square edges and no elevation.

## Architecture

### Tokens and themes

`assets/styles/01-base/design-system.css` is the canonical source for:

- surface, ink, border, brand, and status colors;
- serif and sans-serif font families;
- type sizes;
- application-shell dimensions;
- interaction timing, focus rings, and stacking levels.

Dark, light, and space themes all use the same semantic token names. Radius tokens resolve to `0` and shadow tokens resolve to `none`.

`assets/styles/01-base/theme.css` contains compatibility aliases for older page styles. New CSS must use the canonical `--color-*` tokens rather than introducing another token namespace.

### Application shell

`templates/app-shell.html.twig` owns the shared three-region layout:

1. contextual/global navigation;
2. primary page content;
3. an optional contextual rail.

`layout.html.twig`, `reading-nook-layout.html.twig`, and `newsroom-layout.html.twig` extend the shared shell and provide their own sidebar and contextual content.

The persistent masthead contains:

- the product wordmark;
- global search;
- article creation for authenticated users;
- theme switching;
- login or settings access.

Below 1200px the contextual rail is removed. Below 900px the sidebar becomes a keyboard-accessible drawer controlled by `ui--sidebar-toggle`.

### Navigation

`SidebarNav` renders all global and workspace sidebars. Navigation entries:

- use local Iconoir assets through Symfony UX Icons;
- expose `aria-current="page"` for the active route;
- use a left rule and color rather than a shaded background;
- share one mobile close control and one `leftNav` contract.

Reading Nook contains read-side tools. Newsroom contains authored content, publications, media, and creation actions.

### Content patterns

Article feeds use editorial story rows:

- compact byline and date metadata;
- title and summary as the primary hierarchy;
- an optional restrained thumbnail;
- source labels and actions without floating surfaces;
- container queries for compact layouts.

The public homepage uses one primary heading, one split hero, four product paths, and a final Unfold feature section. Authenticated Home and Discover use the shared page-heading and tab vocabulary.

## Key files

| File | Role |
|---|---|
| `assets/styles/01-base/design-system.css` | Canonical tokens and themes |
| `assets/styles/01-base/theme.css` | Legacy token compatibility aliases |
| `assets/styles/01-base/typography.css` | Editorial type hierarchy and focus treatment |
| `assets/styles/02-layout/header.css` | Persistent masthead |
| `assets/styles/02-layout/shell.css` | Responsive application shell and sidebar |
| `assets/styles/03-components/button.css` | Shared action hierarchy |
| `assets/styles/03-components/tabs.css` | Route/tab navigation |
| `assets/styles/03-components/card.css` | Editorial article rows and card variants |
| `templates/app-shell.html.twig` | Shared layout frame |
| `templates/components/Header.html.twig` | Masthead markup |
| `templates/components/SidebarNav.html.twig` | Shared route-aware sidebar |
| `templates/components/Molecules/Card.html.twig` | Article feed item |
| `assets/controllers/ui/sidebar_toggle_controller.js` | Mobile sidebar behavior |

## Design rules

- Do not add border radii or box shadows to interface containers.
- Do not introduce page-local replacements for canonical color tokens.
- Scope navigation styles with component classes; never target every `nav` element.
- Use EB Garamond for editorial headings and article text, and the system sans-serif stack for interface text.
- Use `:focus-visible`, a minimum 44px interactive target where practical, and `aria-current` for active navigation.
- Put CSS and JavaScript in `assets/`; do not add inline behavior to templates.
- Use local Iconoir assets through `ux_icon()` rather than inline SVG markup.

## Limitations / Known Issues

- Several legacy admin, editor, and media pages retain page-specific geometry. Compatibility aliases keep them functional while they are migrated.
- The application still has one global AssetMapper entrypoint. Editor/admin/media entrypoint separation remains a later performance-oriented cleanup.
- The local development certificate must be trusted by a browser before automated visual inspection can use `https://localhost:8443`.

## Related documentation

- [Navigation Layouts](navigation-layouts-implementation.md)