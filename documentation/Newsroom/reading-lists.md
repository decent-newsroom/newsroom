# Reading Lists

## Overview

Reading lists are curated collections of articles, implemented as Nostr kind 30004 (curation set) events. Users can create multiple lists, add articles from anywhere in the app, and publish them to their relays.

## User Flow

1. **Create**: `/reading-list/wizard/setup` — set title, summary, optional cover image
2. **Add articles**: `/reading-list/wizard/articles` — search or paste naddr coordinates
3. **Review & publish**: `/reading-list/wizard/review` — sign and publish to relays
4. **Quick add**: Floating widget on every page for one-click article additions
5. **Compose**: `/reading-list/compose` — full workspace with sidebar preview and bulk add

## Workflow State Machine

Defined in `config/packages/workflow.yaml` (`reading_list_workflow`). States: `empty → setup → articles → review → published`.

## Key Files

| Component | File |
|-----------|------|
| Controller | `src/Controller/Newsroom/ReadingListController.php` |
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

