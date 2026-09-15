# Article List Browser Cache

The authenticated home feed uses IndexedDB to display cached tab HTML while
refreshing stale content. This is already wired into
`templates/home_authenticated.html.twig` on `home-tab-content` with the
`content--article-list-cache` controller, `data-tab="articles"`, and a five-minute
`data-cache-max-age` of `300000` milliseconds.

## Flow

1. On connection or a `data-tab` change, read the tab's saved HTML from IndexedDB.
2. Show cached content immediately. If absent or older than the configured age,
   request `/home/tab/{tab}` with the `Turbo-Frame: home-tab-content` header.
3. Extract article metadata, update the stored tab snapshot, and replace the
   frame HTML. Request IDs prevent an older tab response from replacing a newer
   selection.
4. Dispatch `content--article-list-cache:content-updated` with the tab name and
   extracted articles.

Refreshes download the full tab HTML; metadata deduplication in IndexedDB does
not turn the HTTP request into a delta request. When IndexedDB reads fail, the
controller attempts the normal server fetch. A failed refresh leaves any
already displayed content available and logs the failure.

The database is `newsroom_articles` (version 1), with `article_tabs` and
`tab_metadata` stores keyed by tab name. Inspect these in browser developer tools
to check saved HTML and freshness timestamps.

## Controller integration

Optional Stimulus targets are `frame`, `spinner`, and `cached`; their full HTML
attribute prefix is `data-content--article-list-cache-target`. Without a `frame`
target, the controller replaces its own element's contents. The existing home
template uses that fallback and does not require a second wrapper.

The controller exposes `clearCache()`, `clearAllCache()`, `refresh()`, and
`prefetchTabs(tabNames)`. Clearing also fetches the current tab. Only prefetch tabs
currently dispatched by [HomeFeedController](home-feed-logged-in.md).

Metadata extraction reads `[data-article-id]`, `[data-uuid]`, or
`[data-coordinate]` elements. An entry needs `data-article-id` or
`data-coordinate` to have a usable ID; optional fields include uuid, npub, title,
and URL.

## Files and limitations

- `assets/controllers/content/article_list_cache_controller.js` - lifecycle and requests
- `assets/controllers/utility/indexeddb-cache.js` - database operations
- `assets/styles/05-utilities/article-list-cache.css` - optional cache indicators
- `templates/home_authenticated.html.twig` - active integration

Storage is local to the browser and origin. The cache does not itself provide a
service worker, cross-device synchronization, or automatic publication-triggered
invalidation. Review cache identity and invalidation before reusing tab names
across different authenticated users or adding more personalized feeds.
