# Discover Page

`/discover` provides article search, topic navigation, and three discovery tabs.

| Tab | Data source |
|---|---|
| Recent | Lazy Turbo Frame from `/discover/tab/recent`; Redis latest-article view with `ContentSearchService` fallback |
| Highlights | `HighlightFeedService::loadLatestHighlights(200)`; the same Redis-first source as `/highlights` |
| Featured writers | Shared `/featured-articles` feed for users with `ROLE_FEATURED_WRITER` |

Recent articles exclude incomplete records, configured exclusions, bots, and the
viewer's muted authors. `app:cache-latest-articles` rebuilds the Redis view every
15 minutes. A cache miss uses local search rather than waiting for relay fetches.

The search form submits to `app_search_index` and uses `search--nostr-redirect`
to recognize Nostr identifiers. Topic links come from `ForumTopics::TOPICS`;
signed-in readers also get links to their interests and named interest sets.

`content--discover-tabs` switches the existing panels and remembers the choice in
`localStorage.discover-tab`. A removed or unknown saved tab falls back to
`articles`, the internal name for Recent. The old Activity and Editorial panels
are no longer part of this page.

## Key files

- `src/Controller/DefaultController.php` - page and Recent frame endpoint
- `templates/pages/discover.html.twig` - search, navigation, and panels
- `templates/discover/tabs/_recent.html.twig` - article list frame
- `assets/controllers/content/discover_tabs_controller.js` - tab selection
- `assets/styles/04-pages/discover.css` - page styles

See [highlights](highlights.md), [featured writers](../Newsroom/featured-writers.md),
and [search](../Newsroom/search.md) for those features.
