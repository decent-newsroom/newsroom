/**
 * IndexedDB Cache Utility for PWA Article Lists
 * Manages offline caching of article list data with sync capabilities
 */
const DB_NAME = 'newsroom_articles';
const DB_VERSION = 1;
const STORE_NAMES = {
  tabs: 'article_tabs',
  metadata: 'tab_metadata'
};
class IndexedDBCache {
  constructor() {
    this.db = null;
  }
  /**
   * Initialize the IndexedDB database
   */
  async init() {
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(DB_NAME, DB_VERSION);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        this.db = request.result;
        resolve(this.db);
      };
      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        // Create object stores if they don't exist
        if (!db.objectStoreNames.contains(STORE_NAMES.tabs)) {
          db.createObjectStore(STORE_NAMES.tabs, { keyPath: 'tabName' });
        }
        if (!db.objectStoreNames.contains(STORE_NAMES.metadata)) {
          db.createObjectStore(STORE_NAMES.metadata, { keyPath: 'tabName' });
        }
      };
    });
  }
  /**
   * Save article list to IndexedDB
   */
  async saveTabContent(tabName, html, articles = []) {
    if (!this.db) await this.init();
    const transaction = this.db.transaction([STORE_NAMES.tabs, STORE_NAMES.metadata], 'readwrite');
    const tabStore = transaction.objectStore(STORE_NAMES.tabs);
    const metaStore = transaction.objectStore(STORE_NAMES.metadata);
    const tabData = {
      tabName,
      html,
      articles,
      savedAt: new Date().getTime()
    };
    const metadata = {
      tabName,
      savedAt: new Date().getTime(),
      articleCount: articles.length,
      etag: this._generateEtag(articles)
    };
    return new Promise((resolve, reject) => {
      const tabRequest = tabStore.put(tabData);
      const metaRequest = metaStore.put(metadata);
      tabRequest.onerror = () => reject(tabRequest.error);
      metaRequest.onerror = () => reject(metaRequest.error);
      transaction.oncomplete = () => resolve({ tabData, metadata });
    });
  }
  /**
   * Retrieve article list from IndexedDB
   */
  async getTabContent(tabName) {
    if (!this.db) await this.init();
    const transaction = this.db.transaction([STORE_NAMES.tabs], 'readonly');
    const store = transaction.objectStore(STORE_NAMES.tabs);
    return new Promise((resolve, reject) => {
      const request = store.get(tabName);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result || null);
    });
  }
  /**
   * Get metadata about a cached tab
   */
  async getTabMetadata(tabName) {
    if (!this.db) await this.init();
    const transaction = this.db.transaction([STORE_NAMES.metadata], 'readonly');
    const store = transaction.objectStore(STORE_NAMES.metadata);
    return new Promise((resolve, reject) => {
      const request = store.get(tabName);
      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result || null);
    });
  }
  /**
   * Merge new articles with cached articles, avoiding duplicates
   */
  async mergeNewArticles(tabName, newArticles, newHtml) {
    if (!this.db) await this.init();
    const cached = await this.getTabContent(tabName);
    if (!cached) {
      return this.saveTabContent(tabName, newHtml, newArticles);
    }
    const newIds = new Set(newArticles.map(a => this._getArticleId(a)));
    const oldArticles = cached.articles.filter(a => !newIds.has(this._getArticleId(a)));
    const mergedArticles = [...newArticles, ...oldArticles];
    return this.saveTabContent(tabName, newHtml, mergedArticles);
  }
  /**
   * Clear cache for a specific tab
   */
  async clearTabCache(tabName) {
    if (!this.db) await this.init();
    const transaction = this.db.transaction([STORE_NAMES.tabs, STORE_NAMES.metadata], 'readwrite');
    return new Promise((resolve, reject) => {
      const tabRequest = transaction.objectStore(STORE_NAMES.tabs).delete(tabName);
      const metaRequest = transaction.objectStore(STORE_NAMES.metadata).delete(tabName);
      tabRequest.onerror = () => reject(tabRequest.error);
      metaRequest.onerror = () => reject(metaRequest.error);
      transaction.oncomplete = () => resolve();
    });
  }
  /**
   * Clear all cached data
   */
  async clearAllCache() {
    if (!this.db) await this.init();
    const transaction = this.db.transaction([STORE_NAMES.tabs, STORE_NAMES.metadata], 'readwrite');
    return new Promise((resolve, reject) => {
      const tabRequest = transaction.objectStore(STORE_NAMES.tabs).clear();
      const metaRequest = transaction.objectStore(STORE_NAMES.metadata).clear();
      tabRequest.onerror = () => reject(tabRequest.error);
      metaRequest.onerror = () => reject(metaRequest.error);
      transaction.oncomplete = () => resolve();
    });
  }
  /**
   * Check if cache is stale
   */
  async isStale(tabName, maxAge = 5 * 60 * 1000) {
    const metadata = await this.getTabMetadata(tabName);
    if (!metadata) return true;
    const age = new Date().getTime() - metadata.savedAt;
    return age > maxAge;
  }
  /**
   * Get all cached tabs metadata
   */
  async getAllMetadata() {
    if (!this.db) await this.init();
    const transaction = this.db.transaction([STORE_NAMES.metadata], 'readonly');
    const store = transaction.objectStore(STORE_NAMES.metadata);
    return new Promise((resolve, reject) => {
      const request = store.getAll();
      request.onerror = () => reject(request.error);
      request.onsuccess = () => resolve(request.result || []);
    });
  }
  _getArticleId(article) {
    if (typeof article === 'string') return article;
    if (article.id) return article.id;
    if (article.uuid) return article.uuid;
    if (article.coordinate) return article.coordinate;
    if (article.npub) return article.npub;
    return JSON.stringify(article);
  }
  _generateEtag(articles) {
    const str = articles.map(a => this._getArticleId(a)).join('|');
    let hash = 0;
    for (let i = 0; i < str.length; i++) {
      const char = str.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash;
    }
    return Math.abs(hash).toString(36);
  }
}
export const indexedDBCache = new IndexedDBCache();
