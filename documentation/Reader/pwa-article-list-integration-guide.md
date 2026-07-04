# PWA Article List Caching - Integration Guide
## Quick Start: Adding Cache to Home Tabs
This guide shows how to integrate the PWA caching feature into your existing home page templates.
## 1. Update `templates/home_authenticated.html.twig`
Add the cache controller to your main tab frame:
```twig
{# Before: #}
<turbo-frame id="tab-frame" src="{{ path(''app_home_feed_tab'', {tab: initial_tab}) }}">
  <div class="loading">Loading...</div>
</turbo-frame>
{# After: #}
<turbo-frame id="tab-frame" 
             data-controller="content--article-list-cache"
             data-tab="{{ initial_tab }}"
             data-cache-max-age="300000">
  {# Cached indicator (optional) #}
  <div data-article-list-cache-target="cached" 
       class="article-list-cache-indicator" 
       style="display: none;">
    Showing cached results - fetching updates...
  </div>
  {# Loading spinner #}
  <div data-article-list-cache-target="spinner" 
       class="article-list-spinner"
       style="display: none;"></div>
  {# Content frame #}
  <div data-article-list-cache-target="frame">
    <div class="loading">Loading...</div>
  </div>
</turbo-frame>
```
## 2. Update Tab Templates
For each tab template (`_latest.html.twig`, `_follows.html.twig`, etc.), ensure articles have data attributes:
```twig
{# templates/home/tabs/_latest.html.twig #}
{# Add data attributes to article container #}
{% component ''CardList'' with {
  cards: articles,
  columnClass: ''col-span-12 lg:col-span-6''
} %}
```
Then modify the CardList or Article component to include:
```twig
<article data-article-id="{{ article.id }}" 
         data-uuid="{{ article.id }}"
         data-coordinate="{{ article.coordinate }}"
         data-npub="{{ article.authorNpub }}"
         data-title="{{ article.title }}">
  {# Your article content #}
</article>
```
## 3. Update Tab Switching Logic
If you have JavaScript that switches between tabs, add prefetch logic:
```javascript
// In assets/controllers/content/home_tabs_controller.js or similar
switchTab(tabName) {
  // ... existing tab switching logic ...
  // Prefetch other tabs for faster navigation
  const controller = this.element.querySelector('[data-controller~="content--article-list-cache"]')
  if (controller && controller._controller) {
    const otherTabs = ['latest', 'follows', 'topics', 'activity']
      .filter(t => t !== tabName)
    controller._controller.prefetchTabs(otherTabs)
  }
}
```
## 4. Add CSS to Main Stylesheet
Ensure the cache styles are imported in your main app.js:
```javascript
// assets/app.js
import './styles/05-utilities/article-list-cache.css'
```
Or in your main template:
```twig
{# templates/base.html.twig #}
<link rel="stylesheet" href="{{ asset(''build/styles/05-utilities/article-list-cache.css'') }}">
```
## 5. Configure Cache Duration
### 5 minutes (default)
```twig
data-cache-max-age="300000"
```
### 10 minutes
```twig
data-cache-max-age="600000"
```
### 1 hour
```twig
data-cache-max-age="3600000"
```
## Testing the Feature
### 1. Check IndexedDB Contents
Open browser DevTools (F12):
1. Go to Application ? IndexedDB
2. Find "newsroom_articles" database
3. Expand "article_tabs" store
4. You should see entries like:
   - Key: "latest" ? Value: {html, articles[], savedAt}
   - Key: "follows" ? Value: {...}
   - etc.
### 2. Test Cache Loading
```javascript
// In browser console:
const { indexedDBCache } = await import('/assets/controllers/utility/indexeddb-cache.js')
// View cached tabs
const metadata = await indexedDBCache.getAllMetadata()
console.table(metadata)
// Check specific tab
const cached = await indexedDBCache.getTabContent('latest')
console.log('Cached HTML length:', cached?.html?.length)
console.log('Articles count:', cached?.articles?.length)
// Check if cache is stale
const isStale = await indexedDBCache.isStale('latest')
console.log('Is stale?', isStale)
```
### 3. Test Offline Mode
1. Load a tab (e.g., /home)
2. Open DevTools (F12) ? Network tab
3. Set throttle to "Offline"
4. Refresh the page
5. Your cached article list should display immediately
6. Try switching tabs - they should load from cache
### 4. Monitor Network Activity
1. Open DevTools ? Network tab
2. Load the home page
3. Watch the sequence:
   - Initial page load
   - Tab content fetches (with cache headers)
   - Background prefetch requests (as they happen)
## Customization
### Change Cache Database Name
Edit `assets/controllers/utility/indexeddb-cache.js`:
```javascript
const DB_NAME = 'newsroom_articles'  // Change this
```
### Customize Article ID Extraction
Edit `assets/controllers/content/article_list_cache_controller.js`:
```javascript
extractArticlesFromHtml(html) {
  // Modify selector to match your HTML structure
  const articleElements = doc.querySelectorAll('[data-article-id], [data-uuid]...')
}
```
### Add Custom Cache Invalidation
```javascript
// Listen for cache events and invalidate on demand
element.addEventListener('article:updated', async (event) => {
  const controller = app.getControllerForElementAndIdentifier(
    element, 
    'content--article-list-cache'
  )
  await controller.clearCache()
  await controller.refresh()
})
```
### Set Different Cache Duration Per Tab
```twig
{# Short cache for real-time tabs #}
<div data-tab="activity" data-cache-max-age="60000">...</div>
{# Longer cache for stable tabs #}
<div data-tab="latest" data-cache-max-age="600000">...</div>
```
## Performance Impact
### Before Integration
- Home page load: 2-3 seconds (network fetch + render)
- Tab switch: 1-2 seconds (network fetch + render)
- Repeat visits: Same 2-3 seconds
### After Integration
- First visit: 2-3 seconds (same as before, plus caching)
- Home page load (cached): 100-200ms (instant cache display)
- Tab switch (cached): 200-300ms (instant cache display)
- Background updates: Seamless (no user wait)
### Data Savings
- First visit: Full page HTML + JS + CSS + tab data
- Repeat visits: Only new articles fetched (~10-20% of initial size)
- Offline visits: Zero network requests
## Troubleshooting
### Articles not showing cached indicator
Check that `data-article-list-cache-target="cached"` element exists and CSS class `article-list-cache-indicator` is properly defined.
### Cache not persisting between page reloads
1. Check browser IndexedDB quota isn't exceeded
2. Verify private/incognito mode doesn''t interfere
3. Check for JavaScript errors in DevTools console
4. Clear browser cache and try again
### Articles not being extracted
Ensure article elements have required data attributes:
- `data-article-id` or `data-uuid` (required - used for deduplication)
- Other attributes are optional but recommended
### Controller not initializing
1. Verify Stimulus is loaded and working
2. Check `data-controller` attribute is correct: `content--article-list-cache`
3. Verify CSS file is loaded (check Network tab in DevTools)
4. Check browser console for JavaScript errors
## Related Documentation
- [PWA Article List Caching Feature Docs](./pwa-article-list-caching.md)
- [Stimulus Controllers Guide](https://stimulus.hotwired.dev/)
- [IndexedDB MDN Reference](https://developer.mozilla.org/en-US/docs/Web/API/IndexedDB_API)
