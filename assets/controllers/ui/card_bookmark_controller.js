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

export default class extends Controller {
  static targets = ['button', 'icon', 'label'];

  static values = {
    coordinate: String,
    bookmarkFetchUrl: String,
    bookmarkPublishUrl: String,
    addLabel: String,
    removeLabel: String,
  };

  connect() {
    this.bookmarkTags = [];
    this.isBookmarked = false;
    this.isSubmitting = false;
    this.hasLocalMutation = false;

    this._onBookmarkStateChanged = this._onBookmarkStateChanged.bind(this);
    document.addEventListener('bookmark:state-changed', this._onBookmarkStateChanged);

    this.fetchBookmarkState();

    if (!retryBootstrapDone && this.hasBookmarkPublishUrlValue && this.bookmarkPublishUrlValue) {
      retryBootstrapDone = true;
      this.bootstrapPendingRetries();
    }
  }

  disconnect() {
    document.removeEventListener('bookmark:state-changed', this._onBookmarkStateChanged);
  }

  async fetchBookmarkState() {
    if (!this.hasBookmarkFetchUrlValue || !this.bookmarkFetchUrlValue) return;

    try {
      const tags = await fetchBookmarkTagsOnce(this.bookmarkFetchUrlValue);
      if (!Array.isArray(tags)) return;
      if (this.hasLocalMutation) return;

      this.bookmarkTags = tags;
      this.isBookmarked = hasCoordinate(this.bookmarkTags, this.coordinateValue);
      this.updateUI();
    } catch (error) {
      console.warn('[card-bookmark] Failed to fetch bookmark state:', error);
    }
  }

  async toggle(event) {
    event.preventDefault();

    if (this.isSubmitting || !this.coordinateValue || !this.hasBookmarkPublishUrlValue) {
      return;
    }

    this.isSubmitting = true;
    this.updateUI();

    try {
      const signer = await getSigner();
      const pubkey = await signer.getPublicKey();

      const baseSnapshot = await this.getBaseSnapshot(pubkey);
      const baseTags = normalizeTags(baseSnapshot?.tags ?? this.bookmarkTags);
      const currentlyBookmarked = hasCoordinate(baseTags, this.coordinateValue);
      const nextTags = toggleCoordinate(baseTags, this.coordinateValue, currentlyBookmarked);

      const skeleton = {
        kind: 10003,
        created_at: Math.floor(Date.now() / 1000),
        tags: nextTags,
        content: '',
        pubkey,
      };

      const signedEvent = await signer.signEvent(skeleton);

      const pendingSnapshot = {
        pubkey,
        tags: nextTags,
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
      this.bookmarkTags = nextTags;
      updateBookmarkStateCache(nextTags);
      this.isBookmarked = !currentlyBookmarked;
      this.updateUI();
      this.broadcastState();

      const published = await this.publishSnapshot(pendingSnapshot);

      if (published) {
        this.toast(this.isBookmarked ? 'Bookmarked!' : 'Bookmark removed', 'success');
      } else {
        this.toast('Bookmark queued for retry', 'warning');
      }
    } catch (error) {
      console.error('[card-bookmark] Toggle failed:', error);
      this.toast('Bookmark failed', 'danger');
    } finally {
      this.isSubmitting = false;
      this.updateUI();
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
        console.warn('[card-bookmark] Retry publish failed:', e);
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
      console.warn('[card-bookmark] Failed to bootstrap pending retries:', error);
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
    this.updateUI();
  }

  broadcastState() {
    document.dispatchEvent(new CustomEvent('bookmark:state-changed', {
      detail: {
        coordinate: this.coordinateValue,
        bookmarked: this.isBookmarked,
      },
    }));
  }

  updateUI() {
    if (this.hasIconTarget) {
      this.iconTarget.setAttribute('fill', this.isBookmarked ? 'currentColor' : 'none');
    }

    if (this.hasLabelTarget) {
      this.labelTarget.textContent = this.isBookmarked
        ? (this.removeLabelValue || 'Bookmarked')
        : (this.addLabelValue || 'Bookmark');
    }

    if (this.hasButtonTarget) {
      this.buttonTarget.disabled = this.isSubmitting;
      this.buttonTarget.classList.toggle('is-active', this.isBookmarked);
      this.buttonTarget.classList.toggle('is-loading', this.isSubmitting);
      this.buttonTarget.title = this.isBookmarked
        ? (this.removeLabelValue || 'Bookmarked')
        : (this.addLabelValue || 'Bookmark');
    }
  }

  async postJSON(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
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

  toast(message, type = 'info', duration = 3000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
    }
  }
}

