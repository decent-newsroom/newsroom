# Article Editor

## Overview

The article editor is an IDE-style split-view interface for creating and editing Nostr long-form articles (kind 30023). It features a Quill rich-text editor with live preview, a collapsible sidebar with organized panels, and full Nostr protocol integration for publishing.

## Layout

- **Header**: Title input, Edit/Preview tabs
- **Main area**: Quill editor (edit mode) or rendered HTML (preview mode)
- **Left sidebar**: Collapsible panels for metadata, mentions, embeds, media
- **Right sidebar**: Metadata panels, advanced settings, publishing controls

### Key Files

| Component | File |
|-----------|------|
| Controller | `src/Controller/Editor/EditorController.php` |
| Markdown sync | `src/Controller/Editor/MarkdownController.php` |
| Layout template | `templates/editor/layout.html.twig` |
| Sidebar panels | `templates/editor/panels/_metadata.html.twig`, `_advanced.html.twig`, `_media.html.twig`, `_publishing.html.twig` |
| Layout CSS | `assets/styles/editor-layout.css` |

### Stimulus Controllers (`assets/controllers/editor/`)

| Controller | Purpose |
|------------|---------|
| `layout_controller.js` | Editor layout management, edit/preview toggle |
| `panels_controller.js` | Sidebar panel open/close |
| `header_controller.js` | Title sync, header interactions |
| `mentions_controller.js` | User search → insert `nostr:npub1…` at cursor |
| `embeds_controller.js` | Profile/article/raw embed insertion |
| `media_controller.js` | Media library integration |
| `markdown-sync_controller.js` | Quill ↔ markdown bidirectional sync |
| `articlelist-panels_controller.js` | Drafts and articles list in sidebar |

## Quill Editor

Uses Quill Snow theme. Key customizations:
- **Link tooltip fix**: Repositioned as `position: fixed` centered in viewport (the default was clipped by `overflow: hidden` on `.ql-container`)
- Global `window.quill` reference exposed for cross-controller access
- Markdown sync via `assets/controllers/editor/conversion.js`

## Advanced Metadata

The editor supports advanced Nostr tags via a collapsible form section:

| Feature | NIP | Tag |
|---------|-----|-----|
| Do not republish | NIP-32 | `L`/`l` policy labels |
| License | NIP-32 | `license` tag (SPDX identifiers) |
| Zap splits | NIP-57 | `zap` tags with weight-based percentages |
| Content warning | NIP-36 | `content-warning` tag |
| Expiration | NIP-40 | `expiration` tag |
| Protected event | NIP-70 | `-` tag |
| Source references | — | `r` tags |
| Media attachments | — | `imeta` tags |

### Key Files
- DTOs: `src/Dto/AdvancedMetadata.php`, `src/Dto/ZapSplit.php`
- Builder: `src/Service/Nostr/NostrEventBuilder.php` — converts DTOs to Nostr tags
- Parser: `src/Service/Nostr/NostrEventParser.php` — parses tags back to DTOs (round-trip safe)
- Form: `src/Form/AdvancedMetadataType.php`, `src/Form/ZapSplitType.php`
- Stimulus: `assets/controllers/content/advanced_metadata_controller.js`

## Publishing Flow

1. User fills in content and metadata in the editor
2. Clicks "Publish" → `nostr_publish_controller.js` collects all fields
3. Scans content for `nostr:` references → auto-generates `p`, `e`, `a` tags (NIP-27)
4. Advanced metadata tags are built client-side via `nostr-utils.ts`
5. Event is signed via NIP-07 browser extension or NIP-46 remote signer
6. Published to user's relay list (direct connections, not via gateway)
7. Server-side safety net in `NostrEventBuilder::extractNostrReferenceTags()` deduplicates tags

## Reactivity & State

Stimulus controllers communicate via:
- **Stimulus values**: Per-controller state (e.g., `data-editor-layout-preview-value`)
- **Stimulus targets**: DOM element references
- **Custom events**: Cross-controller communication (e.g., `quill:ready`, `editor:preview`)
- **Window globals**: `window.quill` for Quill instance access

## Relay Feedback

When publishing, the editor shows per-relay feedback (success/failure/timeout) via toast notifications. Each relay in the user's relay list is contacted independently.

