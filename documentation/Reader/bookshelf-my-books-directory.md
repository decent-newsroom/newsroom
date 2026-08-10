# Bookshelf My Books Directory

## Overview

`My Books` extends the Mercury-backed `/bookshelf` with a personal, signed favorites list stored as a Nostr directory event (kind `30045`) using the stable identifier `d=my-book-collection`.

The feature keeps storage relay-native and user-scoped:

- Books are added as `a` tags (`30040:<pubkey>:<d-tag>`) in a kind `30045` event.
- The directory event `content` stays empty.
- Updates are signed client-side, validated server-side, persisted locally, and published to the user's relays.

## Routes

| Method | Path | Route name | Purpose |
|---|---|---|---|
| `GET` | `/bookshelf/my-books` | `bookshelf_my_books` | Render the authenticated user's favorites directory |
| `POST` | `/api/bookshelf/directory` | `bookshelf_directory_publish` | Accept a signed kind `30045` event, validate, persist, and publish |

## UX

Authenticated users can:

- Add or remove a book from `My Books` directly from `/bookshelf` search results.
- Add or remove a book from the `/bookshelf/{id}` reader header.
- Open `/bookshelf/my-books` from the Bookshelf sidebar and manage the saved list.

The route shows books in the directory order. Missing Mercury items are reported in a notice without removing their references from the signed directory event.

## Data and Validation

`BookshelfDirectoryService` enforces NKBIP-04 constraints for this feature scope:

- Exactly one `d` tag with value `my-book-collection`
- Only `d`, `a`, and `e` tags allowed
- Empty `content`
- Maximum 500 items

Client updates are handled by `assets/controllers/ui/bookshelf_directory_controller.js`, which signs replacement events and posts them to the backend with CSRF protection.

## Key Files

| File | Role |
|---|---|
| `src/BookshelfBundle/Controller/BookshelfDirectoryController.php` | My Books page and publish API |
| `src/BookshelfBundle/Service/Bookshelf/BookshelfDirectoryService.php` | Directory parsing/normalization/validation |
| `assets/controllers/ui/bookshelf_directory_controller.js` | Client-side sign + publish + UI state sync |
| `src/BookshelfBundle/Resources/views/pages/bookshelf.html.twig` | Search page add/remove action wiring |
| `src/BookshelfBundle/Resources/views/bookshelf/read.html.twig` | Reader page add/remove action wiring |
| `src/BookshelfBundle/Resources/views/bookshelf/my_books.html.twig` | Favorites inventory page |
