# Reading Lists

## Overview

Reading lists are curated collections of articles, implemented as Nostr kind 30040 publication-index events with a `type` tag. Users can create multiple lists, add articles from anywhere in the app, and publish them to their relays.

## User Flow

1. **Create**: `/reading-list/wizard/setup` — set title, summary, optional cover image
2. **Curate and publish**: `/reading-list/wizard/articles` — add or remove article coordinates, review resolved titles and coordinates, save the session draft, and sign/publish from one workspace
3. **Quick add**: Floating widget on every page for one-click article additions
4. **Compose**: `/reading-list/compose` — full workspace with sidebar preview and bulk add

The former `/reading-list/wizard/review` URL is retained as a compatibility redirect to the article workspace.

## Consolidated Article Workspace

The article workspace uses the flat editorial hierarchy established by the Newsroom `my-content` page: a large page heading, rule-separated metadata, compact inventory rows, and square controls without shaded cards.

Each article row contains:

- The locally resolved article title and author, loaded through `/api/reading-list/article-preview`
- The full editable Nostr coordinate or `naddr`
- An immediate remove action

`publishing--reading-list-workspace` keeps the article count and Nostr event skeleton synchronized with the visible rows. Valid `naddr` values are normalized in the browser before signing. Invalid coordinates disable publishing and remain visibly marked. Saving the draft is optional for publishing but preserves edits in the wizard session.

## Workflow State Machine

Defined in `config/packages/workflow.yaml` (`reading_list_workflow`). States: `empty → setup → articles → review → published`.

## Key Files

| Component | File |
|-----------|------|
| Controller | `src/Controller/Newsroom/ReadingListController.php` |
| Article workspace | `templates/reading_list/reading_articles.html.twig` |
| Workspace behavior | `assets/controllers/publishing/publishing_reading_list_workspace_controller.js` |
| Workspace styles | `assets/styles/04-pages/reading-list-editor.css` |
| Workflow service | `src/Service/ReadingListWorkflowService.php` |
| List manager | `src/Service/ReadingListManager.php` |
| Navigation | `src/Service/ReadingListNavigationService.php` |
| Selector component | `src/Twig/Components/ReadingListSelectorComponent.php` |
| Draft component | `src/Twig/Components/ReadingListDraftComponent.php` |

## Input Formats

Articles can be added via:
- **naddr**: `nostr:naddr1...` or plain `naddr1...`
- **coordinate**: `30023:pubkey:slug`

## Types

- **standalone**: Independent reading list published to user's relays
- **category**: Reading list used as a category within a magazine (kind 30040)

## Lessons Learned

- **Placeholder fix**: When a reading list references an article not yet in the database, show a placeholder card with the parsed coordinate rather than an empty slot.
