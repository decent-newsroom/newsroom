# Publication Index Routing and Reading List Kinds

## What changed

- `/e/*` resolution for kind `30040` (publication index) now applies redirect rules for all decoded event forms, not only `naddr` lookups.
- When a kind `30040` event references another kind `30040` in its `a` tags, the route resolves to the magazine page.
- When a kind `30040` event references `30023`, `30024`, `30041`, or `30817`, the route resolves to the reading list page.

## Reading list item support

Reading lists now accept and render the following `a`-reference kinds:

- `30023` / `30024` (longform article / draft): existing article-card behavior.
- `30041` (publication chapter): now resolved from `event` storage and shown in the list.
- `30817` (wiki): now resolved from `event` storage and shown in the list.

For `30041` and `30817`, cards link to `/e/naddr...` so item-specific rendering remains delegated to the event route.

## Magazine category wiki support

- Magazine category pages (`/mag/{mag}/cat/{slug}`) now also resolve `a`-references of kind `30817`.
- These wiki entries are rendered through the same `CardList` preview style used for articles.
- Single-item wiki pages now have a dedicated category-aware route: `/mag/{mag}/cat/{cat}/wiki/{slug}`.
- The dedicated wiki page keeps magazine/category breadcrumbs and back navigation while rendering the wiki body using the same content conversion pipeline.

## Notes

- Kind `30817` is now represented as `KindsEnum::WIKI`.
- Magazine vs reading-list redirect selection remains reference-driven (`a` tags), preserving current publication semantics.

