# My Content Publishing Inventory

## Summary

`/my-content` is the authenticated author's publishing workspace. It brings published articles, drafts, reading lists, and curation sets into one compact inventory while keeping reader-owned bookmarks in the Reading Nook.

## Information architecture

- **Published**: the newest revision of each kind `30023` article coordinate.
- **Drafts**: the newest revision of each kind `30024` draft coordinate.
- **Lists**: kind `30040` reading lists and kinds `30004`, `30005`, and `30006` curation sets.
- **Bookmarks**: intentionally excluded. They remain available from the Reading Nook link in the page summary.

The summary strip shows authored-content counts without duplicating large dashboard cards. Tabs filter the inventory by content state, and the search and sort controls use query parameters so filtered views can be bookmarked or shared.

## Inventory behavior

The inventory uses a consistent row model:

- Title and summary or protocol identifier.
- Published, draft, or list status.
- Last update date.
- Item count for lists.
- An overflow menu containing only relevant actions.

Article and draft titles open the editor. Reading-list titles open the list composer, while other curation-set titles open their public view. Protocol coordinates are available from the overflow menu instead of occupying a primary table column.

The controller paginates the filtered inventory at 25 items per page. Article revision deduplication is performed in PostgreSQL with `DISTINCT ON (slug)` through `ArticleRepository::findLatestRevisionsByAuthorAndKind()`, avoiding hydration of every historical revision.

## Deletion

Published articles with an event ID expose a delete action. The action opens an accessible native dialog that explains the NIP-09 behavior before requesting a signature. On confirmation the Stimulus controller:

1. Builds a kind `5` event with `e` and `a` tags.
2. Requests a signature from the configured signer.
3. Publishes the event through the user-context publish endpoint.
4. Reports progress and failure through translated toast messages.

The dialog explicitly notes that relays may retain copies.

## Responsive design

Desktop uses a compact five-column inventory. On narrower screens rows become stacked records with status and update metadata beneath the title. Tabs scroll horizontally without vertical overflow, actions remain in the overflow menu, and the three-value summary stays compact with the Reading Nook link beneath it.

The page follows the shared editorial design system: sharp borders, no rounded surfaces, no shadows, Iconoir icons, and monospace reserved for identifiers.

## Main files

| File | Responsibility |
|---|---|
| `src/Controller/Newsroom/MyContentController.php` | Builds, filters, sorts, and paginates the inventory |
| `src/Repository/ArticleRepository.php` | Fetches the newest article revision per slug |
| `templates/my_content/index.html.twig` | Inventory, controls, menus, and delete dialog |
| `assets/styles/04-pages/my-content.css` | Flat responsive page styling |
| `assets/controllers/content/my_content_delete_controller.js` | Accessible NIP-09 deletion flow |
| `translations/messages.*.yaml` | User-facing copy in all supported locales |
