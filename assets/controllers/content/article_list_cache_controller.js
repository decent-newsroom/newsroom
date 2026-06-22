import { Controller } from '@hotwired/stimulus'
import { indexedDBCache } from '../utility/indexeddb-cache.js'
export default class extends Controller {
  static targets = ['frame', 'spinner', 'cached']
  async connect() {
    this.tabName = this.element.dataset.tab || ''
    this.cacheMaxAge = parseInt(this.element.dataset.cacheMaxAge || '300000', 10)
    if (this.tabName) {
      await this.loadFromCache()
    }
  }
  getFrameElement() {
    return this.hasFrameTarget ? this.frameTarget : this.element
  }
  async loadFromCache() {
    try {
      const cached = await indexedDBCache.getTabContent(this.tabName)
      if (cached) {
        this.displayCachedContent(cached)
        const isStale = await indexedDBCache.isStale(this.tabName, this.cacheMaxAge)
        if (isStale) {
          this.fetchFreshContent()
        }
      } else {
        this.fetchFreshContent()
      }
    } catch (error) {
      console.warn('[ArticleListCache] Cache read failed:', error)
      this.fetchFreshContent()
    }
  }
  displayCachedContent(cached) {
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
    try {
      if (this.hasSpinnerTarget) {
        this.spinnerTarget.style.display = 'block'
      }
      const response = await fetch(`/home/tab/${this.tabName}`, {
        headers: {
          'Accept': 'text/html',
          'X-Requested-With': 'XMLHttpRequest',
          'Turbo-Frame': 'home-tab-content'
        }
      })
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`)
      }
      const html = await response.text()
      const articles = this.extractArticlesFromHtml(html)
      await indexedDBCache.mergeNewArticles(this.tabName, articles, html)
      const frame = this.getFrameElement()
      frame.innerHTML = html
      this.element.classList.remove('from-cache')
      this.dispatch('content-updated', { detail: { tab: this.tabName, articles } })
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

