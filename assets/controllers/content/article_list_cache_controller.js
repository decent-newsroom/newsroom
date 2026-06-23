import { Controller } from '@hotwired/stimulus'
import { indexedDBCache } from '../utility/indexeddb-cache.js'
export default class extends Controller {
  static targets = ['frame', 'spinner', 'cached']
  async connect() {
    this.tabName = this.element.dataset.tab || ''
    this.cacheMaxAge = parseInt(this.element.dataset.cacheMaxAge || '300000', 10)
    this.currentRequestId = 0 // Track which request is active
    // Watch for tab changes
    this.observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.attributeName === 'data-tab') {
          const newTab = this.element.dataset.tab
          if (newTab && newTab !== this.tabName) {
            this.tabName = newTab
            this.loadFromCache()
          }
        }
      })
    })
    this.observer.observe(this.element, {
      attributes: true,
      attributeFilter: ['data-tab']
    })
    if (this.tabName) {
      await this.loadFromCache()
    }
  }
  disconnect() {
    if (this.observer) {
      this.observer.disconnect()
    }
  }
  getFrameElement() {
    return this.hasFrameTarget ? this.frameTarget : this.element
  }
  async loadFromCache() {
    // Increment request ID to invalidate any pending requests for old tabs
    this.currentRequestId++
    const requestId = this.currentRequestId
    const tabName = this.tabName // Capture tab name at the start of request

    try {
      const cached = await indexedDBCache.getTabContent(tabName)

      // Check if this request is still valid (no newer tab switch)
      if (requestId !== this.currentRequestId) return

      if (cached) {
        this.displayCachedContent(cached, requestId)
        const isStale = await indexedDBCache.isStale(tabName, this.cacheMaxAge)

        // Check again before fetching fresh content
        if (requestId !== this.currentRequestId) return

        if (isStale) {
          this.fetchFreshContent()
        }
      } else {
        this.fetchFreshContent()
      }
    } catch (error) {
      console.warn('[ArticleListCache] Cache read failed:', error)
      // Only fetch fresh if still the current request
      if (requestId === this.currentRequestId) {
        this.fetchFreshContent()
      }
    }
  }
  displayCachedContent(cached, requestId) {
    // Verify this is still the active request before updating DOM
    if (requestId && requestId !== this.currentRequestId) return

    const frame = this.getFrameElement()
    if (cached.html) {
      this.element.classList.add('from-cache')
      frame.innerHTML = cached.html
      if (this.hasCachedTarget) {
        this.cachedTarget.style.display = 'block'
        setTimeout(() => {
          this.cachedTarget.style.display = 'none'
        }, 3000)
      }
    }
  }
  async fetchFreshContent() {
    // Capture request ID and tab name to detect stale responses
    const requestId = this.currentRequestId
    const tabName = this.tabName

    try {
      if (this.hasSpinnerTarget) {
        this.spinnerTarget.style.display = 'block'
      }
      const response = await fetch(`/home/tab/${tabName}`, {
        headers: {
          'Accept': 'text/html',
          'X-Requested-With': 'XMLHttpRequest',
          'Turbo-Frame': 'home-tab-content'
        }
      })

      // Check if this request is still valid before processing
      if (requestId !== this.currentRequestId) return

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`)
      }
      const html = await response.text()

      // Check again before displaying
      if (requestId !== this.currentRequestId) return

      const articles = this.extractArticlesFromHtml(html)
      await indexedDBCache.mergeNewArticles(tabName, articles, html)

      // Final check before updating DOM
      if (requestId !== this.currentRequestId) return

      const frame = this.getFrameElement()
      frame.innerHTML = html
      this.element.classList.remove('from-cache')
      this.dispatch('content-updated', { detail: { tab: tabName, articles } })
    } catch (error) {
      console.error('[ArticleListCache] Fetch failed:', error)
    } finally {
      if (this.hasSpinnerTarget) {
        this.spinnerTarget.style.display = 'none'
      }
    }
  }
  extractArticlesFromHtml(html) {
    const articles = []
    const parser = new DOMParser()
    const doc = parser.parseFromString(html, 'text/html')
    const articleElements = doc.querySelectorAll('[data-article-id], [data-uuid], [data-coordinate]')
    articleElements.forEach(el => {
      const article = {
        id: el.dataset.articleId || el.dataset.coordinate,
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
  async clearCache() {
    try {
      await indexedDBCache.clearTabCache(this.tabName)
      this.fetchFreshContent()
    } catch (error) {
      console.error('[ArticleListCache] Clear cache failed:', error)
    }
  }
  async clearAllCache() {
    try {
      await indexedDBCache.clearAllCache()
      this.fetchFreshContent()
    } catch (error) {
      console.error('[ArticleListCache] Clear all cache failed:', error)
    }
  }
  async prefetchTabs(tabNames) {
    try {
      for (const tab of tabNames) {
        const cached = await indexedDBCache.getTabContent(tab)
        const isStale = await indexedDBCache.isStale(tab, this.cacheMaxAge)
        if (!cached || isStale) {
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
  async refresh() {
    await this.clearCache()
  }
}


