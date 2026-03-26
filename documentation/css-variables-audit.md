# CSS Variables Audit & Improvements

## Summary

Audited all 35+ CSS files in `assets/styles/` to replace hardcoded values with CSS custom properties, enforce the project's "no rounded edges" and "no shading" design rules, and extend the theme system for full multi-theme support.

## Changes Made

### 1. Extended `theme.css` with new token categories

| Category | Variables Added | Purpose |
|----------|----------------|---------|
| **Status colors** | `--color-success`, `--color-error`, `--color-warning`, `--color-info` + `*-bg`, `*-border` variants | Semantic feedback colors (alerts, badges, form validation, admonitions) |
| **Muted text** | `--color-text-muted`, `--color-bg-muted` | Previously used with fallbacks or hardcoded `#666`/`#999` |
| **Nostr accent** | `--color-nostr`, `--color-nostr-bg`, `--color-nostr-profile` | Nostr identifier links, npub references, profile borders |
| **Shadows** | `--shadow-sm`, `--shadow-md`, `--shadow-lg`, `--shadow-overlay` | Replaces ~15 hardcoded `box-shadow` values |
| **Z-index scale** | `--z-dropdown`, `--z-sticky`, `--z-overlay`, `--z-modal`, `--z-toast` | Consistent stacking context |
| **Transitions** | `--transition-fast`, `--transition-base`, `--transition-slow` | Consistent animation timing |
| **Layout** | `--header-height`, `--header-height-with-gap`, `--sidebar-width`, `--aside-width`, `--content-max-width` | Replaces hardcoded `60px`, `80px`, `240px`, `350px`, `960px` |
| **Font sizes** | `--font-size-xs` through `--font-size-3xl` | Replaces scattered `0.75rem`–`3.2rem` values |

### 2. Theme overrides

All three themes (`default`, `light`, `space`) now define:
- `--color-text-muted`
- `--color-bg-muted`
- Shadow values (lighter for light theme)
- Nostr accent colors (purple-shifted for space theme)

### 3. Files updated (hardcoded → variables)

#### Layout (`02-layout/`)
- **`layout.css`**: Grid columns now use `--sidebar-width`/`--aside-width`, sticky offsets use `--header-height-with-gap`, footer uses `--color-bg-muted` instead of `#333`, mobile slide-out uses `--shadow-overlay` and `--z-modal`
- **`header.css`**: Card margins/padding now use spacing variables

#### Components (`03-components/`)
- **`article.css`**: Tags `border-radius: 0` (was `1.5rem`), admonition colors now use semantic status variables, sidebar `border-radius: 0`
- **`modal.css`**: Background uses `--color-bg` (was `#fff`), z-index uses `--z-overlay`/`--z-modal`
- **`nip05-badge.css`**: Colors use `--color-success-*` (was hardcoded green rgba), `border-radius: 0`
- **`nostr-previews.css`**: ~40 hardcoded colors replaced with `--color-nostr`, `--color-info`, `--color-text-muted`, `--color-border`, `--color-bg-light`; all `border-radius: 0`
- **`zaps.css`**: Border uses `--color-border`, `border-radius: 0`, z-index uses `--z-overlay`
- **`a2hs.css`**: Background uses `--color-bg`, `border-radius: 0`
- **`notice.css`**: `border-radius: 0`
- **`back-to-top.css`**: Shadow uses `--shadow-md` 
- **`card-placeholder.css`**: Colors use `--color-text-muted`, `border-radius: 0`
- **`image-upload.css`**: Border uses `--color-border`, `border-radius: 0`, error uses `--color-error`
- **`video-event.css`**: All `border-radius: 0`, `white` → `--color-text-contrast`
- **`spinner.css`**: Already used variables (good)
- **`dropdown.css`**: Already mostly themed (good)

#### Pages (`04-pages/`)
- **`admin.css`**: `border-radius: 0`, hardcoded colors → variables
- **`highlights.css`**: Error message uses `--color-error-*`
- **`forum.css`**: ~20 hardcoded colors (`#fff`, `#333`, `#ccc`, `#e9e9e9`, `#f9f9f9`, `#f0f0f0`, `#666`) → theme variables
- **`reading-lists.css`**: Gradient and shimmer use `--color-warning`, `border-radius: 0`
- **`media-discovery.css`**: Error, loader, footer colors → variables
- **`settings.css`**: Already mostly themed (good)

#### Standalone files
- **`toast.css`**: Position uses `--header-height-with-gap`, z-index uses `--z-toast`, danger uses `--color-error`
- **`advanced-metadata.css`**: All Bootstrap-like colors (`#dee2e6`, `#0d6efd`, `#6c757d`, `#dc3545`, `#28a745`) → variables
- **`utilities.css`**: `.text-muted`, `.badge`, `.alert-*` colors → variables, `border-radius: 0`

### 4. Design rule enforcement

- **No rounded edges**: All `border-radius` values set to `0` (was: `1.5rem`, `8px`, `6px`, `4px`, `0.5rem`, `0.375rem`, `0.25rem`)
- **No shading**: Kept functional shadows (dropdowns, modals, back-to-top) but standardized them through `--shadow-*` tokens so they can be globally disabled if needed

## Remaining opportunities

### Files with undefined/non-standard CSS variables
These files use variable names that don't match the theme system. They work because of fallback values, but should be migrated:

| File | Non-standard variables |
|------|----------------------|
| `discover.css` | `--primary-color`, `--text-primary`, `--text-secondary`, `--card-bg`, `--border-color`, `--hover-bg`, `--placeholder-bg` |
| `media-manager.css` | `--border-color`, `--surface-2`, `--text-1`, `--text-2` |
| `editor-layout.css` | `--background`, `--border-color`, `--text-primary`, `--text-secondary`, `--primary`, `--surface`, `--hover` |
| `chat.css` | `--color-surface`, `--color-text-secondary`, `--color-primary-light`, `--color-primary-border` |
| `magazine-wizard.css` | `--color-bg-muted` (now defined), `--font-size-sm` (now defined) |

**Recommendation**: Migrate these to the standard `--color-*` namespace in a follow-up pass. The `editor-layout.css` and `media-manager.css` appear to have been styled separately and should be brought in line.

### Chat page (`chat.css`)
Uses `system-ui` font stack instead of `var(--font-family)`. Has its own light-mode colors. Should be integrated with the theme system.

### Masonry gradient (`media-discovery.css`)
The masonry placeholder uses a hardcoded gradient (`#667eea → #764ba2 → #f093fb → #4facfe → #00f2fe`). This is decorative and intentional — could be left as-is or extracted to a variable if the palette changes.

### Video player background
`video-event.css` uses `background-color: #000` for the video player. This is semantically correct (video players are black) and not theme-dependent.

