# My Content Publishing Inventory

## Summary

Event previews use the shared native lazy disclosure shell. The rollback flag
`EVENT_WORKSPACE_EXPANSION_ENABLED` defaults to `true`; setting it to `false`
hides previews and makes `/my-content/preview/{eventId}` return 404, without
disabling inventory, editor, public reader, or authoring actions. See the
[workspace iteration plan](../Reader/event-workspace-iteration-plan.md#shared-step-2-disclosure-shell-and-rollback)
for the shared rollout boundary and deferred Step 3+ work.

`/my-content` is the authenticated author's publishing workspace. It brings
published articles and drafts into one compact inventory. Reading lists and
magazines have separate Newsroom navigation entries; reader-owned bookmarks
remain in the Reading Nook.

## Information architecture

- **Published**: the newest revision of each kind `30023` article coordinate.
- **Drafts**: the newest revision of each kind `30024` draft coordinate.
- **Lists and magazines**: not included in this inventory. The Newsroom sidebar links to their separate workflows.
- **Bookmarks**: intentionally excluded. The aside links to Reading Nook.

Tabs show authored-content counts and filter the inventory by content state.
Search and sort controls use query parameters so filtered views can be bookmarked.

## Inventory behavior

The inventory uses a consistent row model:

- Title and summary or protocol identifier.
- Published or draft status.
- A display date: publication date when available for published articles,
  otherwise the event creation date.
- An overflow menu containing only relevant actions.

Article and draft titles open the editor. Published articles also offer a public
view, while protocol coordinates are available from the overflow menu. The
menu remains distinct from the event preview disclosure.

## Event preview disclosure

Rows with an exact event ID now offer a separate **Event preview and references**
disclosure, independent of the action menu. It lazily loads the owner-only
`GET /my-content/preview/{eventId}` fragment; its Load link also works without
JavaScript. Published articles and drafts use the same shared escaped
`EventPreview` presentation, without loading images, converting Markdown, or
requiring generated HTML.

The fragment rechecks the authenticated owner, article kind, author suppression,
hidden coordinate, and NIP-09 tombstones on every request. It returns
`Cache-Control: private, no-store`. The exact locally stored Event is preferred;
otherwise a complete Article raw event with matching ID, author, kind, and
coordinate can supply the signed fields. Missing or inconsistent originals show
an explicit unavailable notice, never a fabricated event from projection fields.
Rows without an event ID retain their existing actions without a disclosure.

References are listed in signed tag order, at most 50 per page, retaining
duplicates, malformed occurrences, and source positions. Targets remain
unchecked; no target data, network fetch, persistence, full body rendering, or
recursive expansion is introduced. Sensitive event text and references stay
hidden. The existing inventory filters, counts, 25-row pagination, editor links,
coordinate copy, and signed deletion are unchanged.

The controller displays 25 filtered items per page. Article revision
deduplication is performed in PostgreSQL with `DISTINCT ON (slug)` through
`ArticleRepository::findLatestRevisionsByAuthorAndKind()`, avoiding hydration
of every historical revision. The resulting latest rows are loaded before
filtering, sorting, and pagination in the controller.

## Deletion

Published articles with an event ID expose a delete action. The action opens an accessible native dialog that explains the NIP-09 behavior before requesting a signature. On confirmation the Stimulus controller:

1. Builds a kind `5` event with `e` and `a` tags.
2. Requests a signature from the configured signer.
3. Publishes the event through `POST /api/settings/event/publish`.
4. Reports progress and failure through translated toast messages.

The dialog explicitly notes that relays may retain copies.

## Responsive design

Desktop uses a compact inventory with content, status, date, and action columns.
On narrower screens rows become stacked records with status and update metadata
beneath the title. Tabs scroll horizontally, actions remain in the overflow
menu, and the aside links to Reading Nook.

The page follows the shared editorial design system: sharp borders, no rounded surfaces, no shadows, Iconoir icons, and monospace reserved for identifiers.

This inventory and [Reading Nook](../Reader/reading-nook.md) are the existing
foundation for the proposed [expandable event workspace](../Reader/event-workspace-iteration-plan.md).

## Main files

| File | Responsibility |
|---|---|
| `src/Controller/Newsroom/MyContentController.php` | Builds, filters, sorts, and paginates the inventory |
| `src/Repository/ArticleRepository.php` | Fetches the newest article revision per slug |
| `templates/my_content/index.html.twig` | Inventory, controls, menus, and delete dialog |
| `assets/styles/04-pages/my-content.css` | Flat responsive page styling |
| `assets/controllers/content/my_content_delete_controller.js` | Accessible NIP-09 deletion flow |
| `translations/messages.*.yaml` | User-facing copy in all supported locales |
