import { Controller } from '@hotwired/stimulus'
import { indexedDBCache } from '../utility/indexeddb-cache'
export default class extends Controller {
  static targets = ['frame', 'spinner', 'cached']
  async connect() {
    this.tabName = this.element.dataset.tab || ''
    this.cacheMaxAge = parseInt(this.element.dataset.cacheMaxAge || '300000', 10) // 5 min default
    if (this.tabName) {
      // Try to load from cache first
      await this.loadFromCache()
    }
  }
  /**
   * Load tab content from IndexedDB cache
   */
  async loadFromCache() {
    try {
      const cached = await indexedDBCache.getTabContent(this.tabName)
      if (cached) {
        // Display cached content immediately
        this.displayCachedContent(cached)
        // Check if cache is stale and fetch fresh data
        const isStale = await indexedDBCache.isStale(this.tabName, this.cacheMaxAge)
        if (isStale) {
          this.fetchFreshContent()
        }
      } else {
        // No cache, fetch from server
        this.fetchFreshContent()
      }
    } catch (error) {
      console.warn('[ArticleListCache] Cache read failed:', error)
      // Fallback to fetch if cache fails
      this.fetchFreshContent()
    }
  }
  /**
   * Display cached HTML content
   */
  displayCachedContent(cached) {
    if (this.hasFrameTarget && cached.html) {
      // Mark content as cached
      this.element.classList.add('from-cache')
      // Insert cached HTML into the frame target
      this.frameTarget.innerHTML = cached.html
      // Add visual indicator
      if (this.hasCachedTarget) {
        this.cachedTarget.style.display = 'block'
        setTimeout(() => {
          this.cachedTarget.style.display = 'none'
        }, 3000)
      }
    }
  }
  /**
   * Fetch fresh content from server and update cache
   */
  async fetchFreshContent() {
    try {
      if (this.hasSpinnerTarget) {
        this.spinnerTarget.style.display = 'block'
      }
      const response = await fetch(`/home/tab/${this.tabName}`, {
        headers: {
          'Accept': 'text/html',
          'X-Requested-With': 'XMLHttpRequest',
          'Turbo-Frame': 'tab-frame'
        }
      })
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`)
      }
      const html = await response.text()
      // Extract articles from HTML (look for data attributes or elements)
      const articles = this.extractArticlesFromHtml(html)
      // Save to cache
      await indexedDBCache.mergeNewArticles(this.tabName, articles, html)
      // Update DOM with fresh content
      if (this.hasFrameTarget) {
        this.frameTarget.innerHTML = html
        this.element.classList.remove('from-cache')
      }
      // Dispatch custom event for other listeners
      this.dispatch('content-updated', { detail: { tab: this.tabName, articles } })
    } catch (error) {
      console.error('[ArticleListCache] Fetch failed:', error)
    } finally {
      if (this.hasSpinnerTarget) {
        this.spinnerTarget.style.display = 'none'
      }
    }
  }
  /**
   * Extract articles from HTML response
   * Looks for article data in data-attributes or common DOM patterns
   */
  extractArticlesFromHtml(html) {
    const articles = []
    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')
    // Look for article elements with data attributes
    const articleElements = doc.querySelectorAll('[data-article-id], [data-uuid], [data-coordinate]')
    articleElements.forEach(el => {
      const article = {
        id: el.dataset.articleId || el.dataset.uuid || el.dataset.coordinate,
        uuid: el.dataset.uuid,
        coordinate: el.dataset.coordinate,
        npub: el.dataset.npub,
        title: el.dataset.title,
        url: el.dataset.url
      }
      if (article.id) {
        articles.push(article)
      }
    })
    return articles
  }
  /**
   * Clear cache for this tab
   */
  async clearCache() {
    try {
      await indexedDBCache.clearTabCache(this.tabName)
      this.fetchFreshContent()
    } catch (error) {
      console.error('[ArticleListCache] Clear cache failed:', error)
    }
  }
  /**
   * Clear all cached article lists
   */
  async clearAllCache() {
    try {
      await indexedDBCache.clearAllCache()
      this.fetchFreshContent()
    } catch (error) {
      console.error('[ArticleListCache] Clear all cache failed:', error)
    }
  }
  /**
   * Prefetch other tabs to improve perceived performance
   */
  async prefetchTabs(tabNames) {
    try {
      for (const tab of tabNames) {
        const cached = await indexedDBCache.getTabContent(tab)
        const isStale = await indexedDBCache.isStale(tab, this.cacheMaxAge)
        if (!cached || isStale) {
          // Queue prefetch in background (fire and forget)
          fetch(`/home/tab/${tab}`, {
            headers: {
              'Accept': 'text/html',
              'X-Requested-With': 'XMLHttpRequest'
            }
          }).then(res => res.text())
            .then(html => {
              const articles = this.extractArticlesFromHtml(html)
              return indexedDBCache.saveTabContent(tab, html, articles)
            })
            .catch(err => console.warn(`[ArticleListCache] Prefetch failed for ${tab}:`, err))
        }
      }
    } catch (error) {
      console.warn('[ArticleListCache] Prefetch error:', error)
    }
  }
  /**
   * Refresh current tab content
   */
  async refresh() {
    await this.clearCache()
  }
}
