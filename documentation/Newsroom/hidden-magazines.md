# Hidden Magazines (Admin)

## Overview

Admins can hide specific magazine coordinates from public listings. This is useful for suppressing test events, malformed publications, or any magazine that shouldn't appear on the public newsstand.

Hidden magazines are **not deleted** — they remain in the database and are still visible in the admin panel. They are simply filtered out of all public-facing magazine listings.

## How It Works

### Admin Panel

On the **Admin → Magazines** page (`/admin/magazines`), each magazine now shows:

- A **🚫 Hide** button to hide the magazine from public listings
- A **hidden** badge on already-hidden magazines
- A **👁 Unhide** button to restore visibility

### What Gets Filtered

When a magazine coordinate is hidden, it is excluded from:

| Surface | Implementation |
|---------|---------------|
| **Newsstand** (`/newsstand`) | `GraphMagazineListService` filters via `HiddenCoordinateRepository` |
| **Bookshelf** (`/bookshelf`) | Same graph service filtering |
| **Magazines manifest** (`/magazines/manifest.json`) | `DefaultController::magazinesManifest()` filters hidden coordinates |
| **My Magazines** (`/my-magazines`) | Graph service filters (owner can still access via direct URL) |

### What Is NOT Filtered

- **Direct magazine URLs** (`/mag/{slug}`) — hidden magazines can still be accessed by direct link
- **Admin magazine list** (`/admin/magazines`) — always shows all magazines with hidden status indicated

## Database

The `hidden_coordinate` table stores hidden coordinates:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT (auto) | Primary key |
| `coordinate` | VARCHAR(512) | Nostr coordinate (`30040:pubkey:slug`), unique |
| `reason` | VARCHAR(255) | Optional admin note (not yet exposed in UI) |
| `created_at` | DATETIME | When the coordinate was hidden |

## Files

- `src/Entity/HiddenCoordinate.php` — Doctrine entity
- `src/Repository/HiddenCoordinateRepository.php` — Repository with `hide()`, `unhide()`, `isHidden()`, `findAllCoordinates()` helpers
- `src/Controller/Administration/MagazineAdminController.php` — Hide/unhide POST routes
- `src/Service/Graph/GraphMagazineListService.php` — Filters hidden coordinates from magazine and book listings
- `src/Controller/DefaultController.php` — Filters hidden coordinates from magazines manifest
- `templates/admin/magazines.html.twig` — UI for hide/unhide toggle
- `migrations/Version20260324120000.php` — Creates the `hidden_coordinate` table

