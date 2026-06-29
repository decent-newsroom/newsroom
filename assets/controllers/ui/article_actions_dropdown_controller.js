import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

const DB_NAME = 'newsroom-bookmarks';
const DB_VERSION = 1;
const STORE_NAME = 'bookmark-events';
const RETRY_BASE_DELAY_MS = 1200;
const RETRY_MAX_DELAY_MS = 30000;

const retryTimers = new Map();
let retryBootstrapDone = false;
let bookmarkStateCache = null;
let bookmarkStatePromise = null;

function openDb() {
  return new Promise((resolve, reject) => {
    const request = window.indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = (event) => {
      const db = event.target.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'pubkey' });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error || new Error('IndexedDB open failed'));
  });
}

async function withStore(mode, run) {
  if (!window.indexedDB) return null;

  const db = await openDb();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, mode);
    const store = tx.objectStore(STORE_NAME);

    const request = run(store);
    tx.oncomplete = () => resolve(request?.result ?? null);
    tx.onerror = () => reject(tx.error || new Error('IndexedDB transaction failed'));
    tx.onabort = () => reject(tx.error || new Error('IndexedDB transaction aborted'));
  }).finally(() => db.close());
}

function normalizeTags(tags) {
  if (!Array.isArray(tags)) return [];
  return tags.filter((tag) => Array.isArray(tag) && tag.length >= 2);
}

async function readSnapshot(pubkey) {
  return withStore('readonly', (store) => store.get(pubkey));
}

async function writeSnapshot(snapshot) {
  return withStore('readwrite', (store) => store.put(snapshot));
}

async function readAllSnapshots() {
  const rows = await withStore('readonly', (store) => store.getAll());
  return Array.isArray(rows) ? rows : [];
}

function hasCoordinate(tags, coordinate) {
  return tags.some((tag) => Array.isArray(tag) && tag[0] === 'a' && tag[1] === coordinate);
}

function toggleCoordinate(tags, coordinate, remove) {
  const filtered = tags.filter(
    (tag) => !(Array.isArray(tag) && tag[0] === 'a' && tag[1] === coordinate)
  );

  if (!remove) {
    filtered.push(['a', coordinate]);
  }

  return filtered;
}

function getRetryDelayMs(retryCount) {
  const backoff = RETRY_BASE_DELAY_MS * Math.pow(2, Math.max(0, retryCount - 1));
  return Math.min(backoff, RETRY_MAX_DELAY_MS);
}

async function fetchBookmarkTagsOnce(fetchUrl) {
  if (Array.isArray(bookmarkStateCache)) {
    return bookmarkStateCache;
  }

  if (bookmarkStatePromise) {
    return bookmarkStatePromise;
  }

  bookmarkStatePromise = fetch(fetchUrl, {
    headers: { Accept: 'application/json' },
  }).then(async (response) => {
    if (!response.ok) {
      return null;
    }

    const data = await response.json();
    bookmarkStateCache = normalizeTags(data.tags || []);
    return bookmarkStateCache;
  }).finally(() => {
    bookmarkStatePromise = null;
  });

  return bookmarkStatePromise;
}

function updateBookmarkStateCache(tags) {
  bookmarkStateCache = normalizeTags(tags);
}

/**
 * Article Actions Dropdown Controller
 *
 * Consolidates share (copy), bookmark, broadcast, and highlights toggle
 * into a single dropdown. Keeps the action bar clean by grouping secondary
 * actions behind a kebab menu (⋮).
 *
 * All publish feedback is routed through the global toast notification
 * system (window.showToast) instead of inline status elements.
 */
export default class extends Controller {
  static targets = [
    'trigger', 'menu',
    'bookmarkItem', 'bookmarkIcon', 'bookmarkLabel',
    'broadcastItem',
  ];

  static values = {
    coordinate: String,         // "30023:<pubkey>:<slug>"
    bookmarkFetchUrl: String,   // GET /api/bookmarks/current
    bookmarkPublishUrl: String, // POST /api/bookmarks/publish
  };

  connect() {
    this.isBookmarked = false;
    this.bookmarkTags = [];
    this.isSubmitting = false;
    this.hasLocalMutation = false;
    this.menuOpen = false;
    this._closeOnOutsideClick = this._closeOnOutsideClick.bind(this);
    this._onBookmarkStateChanged = this._onBookmarkStateChanged.bind(this);

    document.addEventListener('bookmark:state-changed', this._onBookmarkStateChanged);

    // Fetch bookmark state if user is logged in
    if (this.hasBookmarkFetchUrlValue && this.bookmarkFetchUrlValue) {
      this.fetchBookmarkState();
    }

    if (!retryBootstrapDone && this.hasBookmarkPublishUrlValue && this.bookmarkPublishUrlValue) {
      retryBootstrapDone = true;
      this.bootstrapPendingRetries();
    }
  }

  disconnect() {
    document.removeEventListener('click', this._closeOnOutsideClick);
    document.removeEventListener('bookmark:state-changed', this._onBookmarkStateChanged);
  }

  // ── Dropdown toggle ─────────────────────────────────────────

  toggle(event) {
    event.stopPropagation();
    if (this.menuOpen) {
      this.close();
    } else {
      this.open();
    }
  }

  open() {
    this.menuTarget.classList.add('show');
    this.menuOpen = true;
    this.triggerTarget.setAttribute('aria-expanded', 'true');
    // Defer adding the outside-click listener so the current click
    // event doesn't immediately close the menu.
    requestAnimationFrame(() => {
      document.addEventListener('click', this._closeOnOutsideClick);
    });
  }

  close() {
    this.menuTarget.classList.remove('show');
    this.menuOpen = false;
    this.triggerTarget.setAttribute('aria-expanded', 'false');
    document.removeEventListener('click', this._closeOnOutsideClick);
  }

  _closeOnOutsideClick(event) {
    if (!this.element.contains(event.target)) {
      this.close();
    }
  }

  // ── Copy to clipboard ───────────────────────────────────────

  copy(event) {
    event.preventDefault();
    const text = event.currentTarget.dataset.copy;
    if (!text) return;

    navigator.clipboard.writeText(text).then(() => {
      this.toast('Copied!', 'success');
      this.close();
    }).catch(() => {
      this.toast('Failed to copy', 'danger');
    });
  }

  // ── Bookmark ────────────────────────────────────────────────

  async fetchBookmarkState() {
    try {
      const tags = await fetchBookmarkTagsOnce(this.bookmarkFetchUrlValue);
      if (!Array.isArray(tags)) return;
      if (this.hasLocalMutation) return;

      this.bookmarkTags = tags;

      this.isBookmarked = hasCoordinate(this.bookmarkTags, this.coordinateValue);

      this.updateBookmarkUI();
    } catch (e) {
      console.warn('[article-actions] Failed to fetch bookmark state:', e);
    }
  }

  async toggleBookmark(event) {
    event.preventDefault();
    this.close();

    if (this.isSubmitting || !this.hasBookmarkPublishUrlValue) return;

    this.isSubmitting = true;
    this.updateBookmarkUI();

    let signer;
    try {
      this.toast('Connecting to signer…', 'info');
      signer = await getSigner();
    } catch (e) {
      this.toast('No Nostr signer available', 'danger');
      this.isSubmitting = false;
      this.updateBookmarkUI();
      return;
    }

    try {
      const pubkey = await signer.getPublicKey();
      const baseSnapshot = await this.getBaseSnapshot(pubkey);
      const baseTags = normalizeTags(baseSnapshot?.tags ?? this.bookmarkTags);
      const currentlyBookmarked = hasCoordinate(baseTags, this.coordinateValue);
      const newTags = toggleCoordinate(baseTags, this.coordinateValue, currentlyBookmarked);

      const skeleton = {
        kind: 10003,
        created_at: Math.floor(Date.now() / 1000),
        tags: newTags,
        content: '',
        pubkey,
      };

      const signedEvent = await signer.signEvent(skeleton);

      const pendingSnapshot = {
        pubkey,
        tags: newTags,
        event: signedEvent,
        status: 'pending',
        retryCount: 0,
        nextRetryAt: null,
        lastError: null,
        updatedAt: Date.now(),
        lastSuccessAt: baseSnapshot?.lastSuccessAt || null,
      };

      await writeSnapshot(pendingSnapshot);

      this.hasLocalMutation = true;
      this.isBookmarked = !currentlyBookmarked;
      this.bookmarkTags = newTags;
      updateBookmarkStateCache(newTags);
      this.updateBookmarkUI();
      this.broadcastState();

      const published = await this.publishSnapshot(pendingSnapshot);

      if (published) {
        const action = this.isBookmarked ? 'Bookmarked!' : 'Bookmark removed';
        this.toast(action, 'success');
      } else {
        this.toast('Bookmark queued for retry', 'warning');
      }
    } catch (error) {
      console.error('[article-actions] Bookmark error:', error);
      this.toast(`Bookmark failed: ${error.message}`, 'danger');
    } finally {
      this.isSubmitting = false;
      this.updateBookmarkUI();
    }
  }

  async getBaseSnapshot(pubkey) {
    const cached = await readSnapshot(pubkey);
    if (cached && Array.isArray(cached.tags)) {
      return cached;
    }

    const tagsFromServer = await this.fetchTagsFromServer();

    const seeded = {
      pubkey,
      tags: tagsFromServer,
      event: null,
      status: 'synced',
      retryCount: 0,
      nextRetryAt: null,
      lastError: null,
      updatedAt: Date.now(),
      lastSuccessAt: Date.now(),
    };

    await writeSnapshot(seeded);

    return seeded;
  }

  async fetchTagsFromServer() {
    if (!this.hasBookmarkFetchUrlValue || !this.bookmarkFetchUrlValue) {
      return [];
    }

    try {
      const tags = await fetchBookmarkTagsOnce(this.bookmarkFetchUrlValue);
      return Array.isArray(tags) ? tags : [];
    } catch {
      return [];
    }
  }

  async publishSnapshot(snapshot) {
    if (!snapshot?.event || !this.hasBookmarkPublishUrlValue || !this.bookmarkPublishUrlValue) {
      return false;
    }

    try {
      await this.postJSON(this.bookmarkPublishUrlValue, { event: snapshot.event });

      const published = {
        ...snapshot,
        status: 'published',
        retryCount: 0,
        nextRetryAt: null,
        lastError: null,
        updatedAt: Date.now(),
        lastSuccessAt: Date.now(),
      };

      await writeSnapshot(published);
      retryTimers.delete(snapshot.pubkey);
      return true;
    } catch (error) {
      await this.scheduleRetry(snapshot, error);
      return false;
    }
  }

  async scheduleRetry(snapshot, error = null) {
    const retryCount = (snapshot.retryCount || 0) + 1;
    const delay = getRetryDelayMs(retryCount);
    const nextRetryAt = Date.now() + delay;

    const pending = {
      ...snapshot,
      status: 'pending',
      retryCount,
      nextRetryAt,
      lastError: error?.message || null,
      updatedAt: Date.now(),
    };

    await writeSnapshot(pending);

    this.scheduleRetryTimer(pending.pubkey, Math.max(300, delay));
  }

  scheduleRetryTimer(pubkey, waitMs) {
    if (retryTimers.has(pubkey)) {
      clearTimeout(retryTimers.get(pubkey));
    }

    const timer = setTimeout(() => {
      retryTimers.delete(pubkey);
      this.retryPublish(pubkey).catch((e) => {
        console.warn('[article-actions] Retry publish failed:', e);
      });
    }, waitMs);

    retryTimers.set(pubkey, timer);
  }

  async retryPublish(pubkey) {
    const snapshot = await readSnapshot(pubkey);

    if (!snapshot || snapshot.status !== 'pending' || !snapshot.event) {
      return;
    }

    const waitMs = Math.max(0, (snapshot.nextRetryAt || 0) - Date.now());
    if (waitMs > 0) {
      this.scheduleRetryTimer(snapshot.pubkey, waitMs);
      return;
    }

    await this.publishSnapshot(snapshot);
  }

  async bootstrapPendingRetries() {
    try {
      const snapshots = await readAllSnapshots();

      for (const snapshot of snapshots) {
        if (!snapshot || snapshot.status !== 'pending' || !snapshot.pubkey) {
          continue;
        }

        if (retryTimers.has(snapshot.pubkey)) {
          continue;
        }

        const waitMs = Math.max(0, (snapshot.nextRetryAt || Date.now()) - Date.now());
        this.scheduleRetryTimer(snapshot.pubkey, waitMs);
      }
    } catch (error) {
      console.warn('[article-actions] Failed to bootstrap pending retries:', error);
    }
  }

  _onBookmarkStateChanged(event) {
    const detail = event.detail || {};

    if (Array.isArray(detail.tags)) {
      this.bookmarkTags = normalizeTags(detail.tags);
      updateBookmarkStateCache(detail.tags);
    }

    if (!detail.coordinate || detail.coordinate !== this.coordinateValue) {
      return;
    }

    if (typeof detail.bookmarked !== 'boolean') {
      return;
    }

    this.isBookmarked = detail.bookmarked;
    this.updateBookmarkUI();
  }

  broadcastState() {
    document.dispatchEvent(new CustomEvent('bookmark:state-changed', {
      detail: {
        coordinate: this.coordinateValue,
        bookmarked: this.isBookmarked,
      },
    }));
  }

  updateBookmarkUI() {
    if (this.hasBookmarkIconTarget) {
      this.bookmarkIconTarget.setAttribute('fill', this.isBookmarked ? 'currentColor' : 'none');
    }
    if (this.hasBookmarkLabelTarget) {
      this.bookmarkLabelTarget.textContent = this.isBookmarked ? 'Bookmarked' : 'Bookmark';
    }
    if (this.hasBookmarkItemTarget) {
      this.bookmarkItemTarget.classList.toggle('dropdown-item--active', this.isBookmarked);
      this.bookmarkItemTarget.disabled = this.isSubmitting;
    }
  }

  // ── Broadcast ───────────────────────────────────────────────

  async broadcast(event) {
    return this._doBroadcast(event, null);
  }

  /**
   * Broadcast to the Essayist relay only. Member/admin-only action wired
   * from the dropdown — the button supplies the relay URL via
   * `data-relays` (a single-entry array containing the Essayist WSS URL).
   */
  async broadcastEssayist(event) {
    return this._doBroadcast(event, 'Essayist');
  }

  async _doBroadcast(event, targetLabel) {
    event.preventDefault();
    this.close();

    const btn = event.currentTarget;
    const coordinate = btn.dataset.coordinate;
    const articleId = btn.dataset.articleId;
    const label = targetLabel || btn.dataset.targetLabel || null;
    let relays;
    try { relays = JSON.parse(btn.dataset.relays || '[]'); } catch { relays = []; }

    btn.classList.add('loading');

    try {
      const payload = {};
      if (articleId) payload.article_id = parseInt(articleId, 10);
      if (coordinate) payload.coordinate = coordinate;
      if (relays.length > 0) payload.relays = relays;

      const data = await this.postJSON('/api/broadcast-article', payload);

      if (data.success) {
        const where = label ? ` to ${label}` : '';
        this.toast(`Broadcast${where}: ${data.broadcast.successful}/${data.broadcast.total_relays} relays`, 'success', 5000);
      } else {
        throw new Error(data.error || 'Broadcast failed');
      }
    } catch (error) {
      console.error('[article-actions] Broadcast error:', error);
      this.toast(`Broadcast failed: ${error.message}`, 'danger', 5000);
    } finally {
      btn.classList.remove('loading');
    }
  }

  // ── Highlights ──────────────────────────────────────────────

  toggleHighlights(event) {
    event.preventDefault();
    this.close();

    // Dispatch to the existing ui--highlights-toggle controller
    // which lives on a parent <article> element.
    const article = this.element.closest('[data-controller*="ui--highlights-toggle"]');
    if (article) {
      const btn = article.querySelector('[data-ui--highlights-toggle-target="button"]');
      if (btn) btn.click();
    }
  }

  // ── Helpers ─────────────────────────────────────────────────

  async postJSON(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(body),
    });

    const contentType = response.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      throw new Error(`Server returned non-JSON response (${response.status})`);
    }

    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }
    return data;
  }

  /**
   * Show a toast notification via the global toast system.
   */
  toast(message, type = 'info', duration = 3000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
    } else {
      console.log(`[article-actions] ${type}: ${message}`);
    }
  }
}
