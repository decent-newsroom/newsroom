# Magazine Index Deletion

Administrators can permanently remove a stale magazine index and every nested kind-30040 index it references from the magazine administration page.

## Overview

The delete action is intended for abandoned or malformed magazine structures that should no longer remain in the local database. It is coordinate-scoped: indexes belonging to other magazines or authors, and all referenced article events, are preserved. The browser requires an explicit confirmation and the POST action is CSRF protected.

## Architecture

### Data model

- `event` — stores the root magazine index and its nested kind-30040 category indexes.
- `magazine` — deprecated projection table; the matching root projection is removed with the index tree.

### Flow

1. An administrator selects **Delete indexes** for a magazine.
2. The controller validates the CSRF token and passes its root `30040:pubkey:d-tag` coordinate to the deletion service.
3. The service resolves the current index tree through kind-30040 `a` tags.
4. A database transaction removes all persisted revisions for the resolved index coordinates and the matching root projection.

### Key files

| File | Role |
|---|---|
| `src/Controller/Administration/MagazineAdminController.php` | Admin POST endpoint |
| `src/Service/Magazine/MagazineIndexDeletionService.php` | Coordinate-scoped recursive traversal and deletion |
| `templates/admin/magazines.html.twig` | Confirmed delete control |

## Limitations / Known Issues

- This deletes local copies only. If the same events are fetched from a relay later, they can be stored again.
- Article events referenced by an index are deliberately not deleted because they can belong to other publications.

## Related NIPs / NKBIPs

- [NKBIP-01](../NKBIP/01.md) — publication index events use kind 30040 and address coordinates.
