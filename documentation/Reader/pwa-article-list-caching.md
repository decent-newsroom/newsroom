# PWA Article List Caching
## Overview
This feature implements offline-first caching for article lists on the home page using IndexedDB and a Stimulus controller. When a user loads an article list, it is automatically cached in the browser''s IndexedDB. On subsequent visits, the cached content is displayed immediately, and new items are fetched from the server to keep the list fresh.
### Key Benefits
- **Instant Page Load**: Cached content displays immediately on page load without waiting for network requests
- **Offline Support**: Users can browse previously cached article lists even without internet connection
- **Smart Syncing**: New items are automatically fetched and merged with cached content
- **Configurable Cache Duration**: Set how long cached data should be considered fresh
- **Prefetching**: Optional background prefetching of other tabs for even faster navigation
## Architecture
### IndexedDB Storage (`assets/controllers/utility/indexeddb-cache.js`)
The `IndexedDBCache` class manages all IndexedDB operations:
```javascript
import { indexedDBCache } from '../utility/indexeddb-cache'
// Save article list to cache
await indexedDBCache.saveTabContent(tabName, html, articles)
// Retrieve from cache
const cached = await indexedDBCache.getTabContent(tabName)
// Check if cache is stale (older than 5 minutes)
const isStale = await indexedDBCache.isStale(tabName, 5 * 60 * 1000)
// Merge new articles with cached ones
await indexedDBCache.mergeNewArticles(tabName, newArticles, newHtml)
// Clear specific tab cache
await indexedDBCache.clearTabCache(tabName)
// Clear all caches
await indexedDBCache.clearAllCache()
```
**Database Schema:**
Two object stores are created:
1. **article_tabs** - Stores article list data
   - Key: `tabName` (string, e.g., "latest", "follows", "foryou")
   - Value:
     ```
     {
       tabName: string,
       html: string,                    // HTML content of the list
       articles: Array<Article>,        // Extracted article metadata
       savedAt: number                  // Timestamp in ms
     }
     ```
2. **tab_metadata** - Stores cache metadata
   - Key: `tabName`
   - Value:
     ```
     {
       tabName: string,
       savedAt: number,                 // When the cache was created
       articleCount: number,            // Number of articles
       etag: string                     // Simple hash for comparison
     }
     ```
### Stimulus Controller (`assets/controllers/content/article_list_cache_controller.js`)
The `ArticleListCacheController` orchestrates the caching strategy:
**Controller Targets:**
- `frame` - Element where article list HTML is inserted
- `spinner` - Loading indicator element
- `cached` - Cached state notification element
**Data Attributes:**
- `data-tab` - Tab name (required, e.g., "latest")
- `data-cache-max-age` - Cache freshness duration in ms (default: 300000 = 5 min)
**Methods:**
- `connect()` - Initializes the controller when Stimulus connects
- `loadFromCache()` - Attempts to load from IndexedDB
- `displayCachedContent(cached)` - Renders cached HTML
- `fetchFreshContent()` - Fetches latest data from server
- `extractArticlesFromHtml(html)` - Parses article metadata from HTML
- `clearCache()` - Removes cache for current tab
- `clearAllCache()` - Removes all caches
- `prefetchTabs(tabNames)` - Background-fetches other tabs
- `refresh()` - Forces cache invalidation and refetch
**Cache Loading Flow:**
1. Controller connects to element
2. Checks if cache exists in IndexedDB
3. If cached:
   - Display cached content immediately (instant UX)
   - Check if stale (based on `cacheMaxAge`)
   - If stale, fetch fresh content in background
4. If not cached:
   - Fetch from server immediately
## Usage
### 1. Add to a Twig Template
Wrap your article list in a container with the Stimulus controller:
```twig
<div data-controller="content--article-list-cache" 
     data-tab="latest"
     data-cache-max-age="300000">
  <div data-article-list-cache-target="spinner" class="article-list-spinner"></div>
  <div data-article-list-cache-target="cached" class="article-list-cache-indicator">
    Showing cached results
  </div>
  <div data-article-list-cache-target="frame" id="tab-frame">
    <!-- Article list will be inserted here -->
  </div>
</div>
```
### 2. Ensure Article Elements Have Data Attributes
For proper article extraction and deduplication, add data attributes to article elements:
```twig
<article data-article-id="{{ article.uuid }}" 
         data-uuid="{{ article.uuid }}"
         data-coordinate="{{ article.coordinate }}"
         data-npub="{{ article.authorNpub }}"
         data-title="{{ article.title }}"
         data-url="{{ article.url }}">
  <!-- Article content -->
</article>
```
### 3. Configure Cache Duration
The default cache is valid for 5 minutes (300000 ms). Adjust as needed:
```twig
<div data-controller="content--article-list-cache" 
     data-tab="latest"
     data-cache-max-age="600000">  <!-- 10 minutes -->
  ...
</div>
```
### 4. Optional: Prefetch Other Tabs
Trigger background prefetching of other tabs from JavaScript:
```javascript
const controller = app.getControllerForElementAndIdentifier(element, 'content--article-list-cache')
await controller.prefetchTabs(['follows', 'topics', 'foryou'])
```
### 5. Manual Cache Control
Clear cache from JavaScript:
```javascript
// Clear specific tab
await controller.clearCache()
// Clear all tabs
await controller.clearAllCache()
// Refresh current tab
await controller.refresh()
```
## Integration Guide
### Step 1: Include CSS
Add the cache styles to your main CSS import in `assets/app.js` or main template:
```javascript
import '../styles/05-utilities/article-list-cache.css'
```
### Step 2: Modify Tab Templates
Update `templates/home/tabs/_*.html.twig` to include cache indicators:
```twig
<div data-controller="content--article-list-cache" 
     data-tab="{{ tab_name }}"
     class="article-list-container">
  <div class="article-list-cache-indicator" data-article-list-cache-target="cached">
    Loaded from cache - fetching updates...
  </div>
  <div data-article-list-cache-target="frame">
    <!-- Content here -->
  </div>
</div>
```
### Step 3: Update Home Page Template
Modify `templates/home_authenticated.html.twig` to wrap tab frames:
```twig
<turbo-frame id="tab-frame" src="{{ path(''app_home_feed_tab'', {tab: ''latest''}) }}">
  <div data-controller="content--article-list-cache" 
       data-tab="latest">
    <!-- Frame content will be loaded here -->
  </div>
</turbo-frame>
```
## Event Dispatching
The controller dispatches custom Stimulus events:
```javascript
// Listen for cache updates
element.addEventListener('content-updated', (event) => {
  console.log('Tab updated:', event.detail.tab)
  console.log('Articles:', event.detail.articles)
})
```
## Performance Considerations
### Cache Size
- Each tab stores full HTML + article metadata
- Typical cache per tab: 50-200 KB
- Total for 4 tabs: ~200-800 KB (well within IndexedDB quotas)
### IndexedDB Quota
- Modern browsers typically allow 50+ MB per origin
- This implementation will never exceed a few MB
### Network Usage
- First load: Full HTTP request
- Subsequent loads: Cached display + background fetch for delta updates
- Reduced bandwidth: Only new items fetched, not entire list
### Memory Considerations
- DOMParser creates temporary DOM for article extraction
- Cleanup occurs automatically after each operation
- No persistent memory leaks
## Browser Support
| Browser | IndexedDB | Service Worker | Support |
|---------|-----------|-----------------|---------|
| Chrome 90+ | ✓ | ✓ | Full |
| Firefox 88+ | ✓ | ✓ | Full |
| Safari 15+ | ✓ | ✓ | Full |
| Edge 90+ | ✓ | ✓ | Full |
| Mobile Safari 15+ | ✓ | ✓ | Full |
For older browsers, the controller gracefully falls back to traditional server requests (no caching).
## Debugging
### Enable Logging
Open browser DevTools and check console logs:
```javascript
// In ArticleListCacheController
console.warn('[ArticleListCache] Cache read failed:', error)
console.error('[ArticleListCache] Fetch failed:', error)
console.log('Article list cached successfully')
```
### Inspect IndexedDB
In Chrome DevTools:
1. Open DevTools (F12)
2. Go to Application → IndexedDB
3. Find "newsroom_articles" database
4. Inspect "article_tabs" and "tab_metadata" object stores
### Manual Cache Operations
```javascript
// Access from console
const { indexedDBCache } = await import('/assets/controllers/utility/indexeddb-cache.js')
// View all metadata
const metadata = await indexedDBCache.getAllMetadata()
console.table(metadata)
// Clear all cache
await indexedDBCache.clearAllCache()
// Check specific tab
const cached = await indexedDBCache.getTabContent('latest')
console.log(cached)
```
## Caveats and Limitations
1. **HTML Structure Dependency**: Article extraction relies on data attributes. If HTML changes format, extraction logic may need updating.
2. **Cache Invalidation**: No automatic invalidation when new articles are published. Users may see old articles until cache expires or they manually refresh.
3. **Per-Device Cache**: IndexedDB is per-browser/device. Syncing across devices requires cloud storage.
4. **Private Browsing**: IndexedDB may not persist in private/incognito mode depending on browser settings.
5. **Quota Limits**: Very large article lists (1000+ items) may approach browser storage limits.
## Future Enhancements
- [ ] Implement Service Worker for more granular offline support
- [ ] Add cache sync API for background updates
- [ ] Implement LZ4 compression for cached HTML
- [ ] Add cache stats dashboard for users
- [ ] Implement cache versioning for migrations
- [ ] Add selective cache retention policies (keep latest N items)
## Related Files
- `assets/controllers/utility/indexeddb-cache.js` - Core IndexedDB operations
- `assets/controllers/content/article_list_cache_controller.js` - Stimulus controller
- `assets/styles/05-utilities/article-list-cache.css` - Styling
- `templates/home/tabs/*.html.twig` - Tab templates needing integration
## See Also
- [PWA Fundamentals](https://web.dev/progressive-web-apps/)
- [IndexedDB API](https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API)
- [Stimulus Controllers](https://stimulus.hotwired.dev/)
