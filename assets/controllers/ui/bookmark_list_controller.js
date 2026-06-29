import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

const DB_NAME = 'newsroom-bookmarks';
const DB_VERSION = 1;
const STORE_NAME = 'bookmark-events';
const STANDARD_BOOKMARK_KIND = 10003;

function normalizeTags(tags) {
  if (!Array.isArray(tags)) {
    return [];
  }

  return tags.filter((tag) => Array.isArray(tag) && tag.length >= 2);
}

function removeTag(tags, type, value) {
  return normalizeTags(tags).filter(
    (tag) => !(tag[0] === type && tag[1] === value)
  );
}

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

async function readSnapshot(pubkey) {
  if (!window.indexedDB) {
    return null;
  }

  const db = await openDb();

  return new Promise((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readonly');
    const request = transaction.objectStore(STORE_NAME).get(pubkey);

    transaction.oncomplete = () => resolve(request.result || null);
    transaction.onerror = () => reject(transaction.error || new Error('IndexedDB read failed'));
    transaction.onabort = () => reject(transaction.error || new Error('IndexedDB read aborted'));
  }).finally(() => db.close());
}

async function writeSnapshot(snapshot) {
  if (!window.indexedDB) {
    return;
  }

  const db = await openDb();

  return new Promise((resolve, reject) => {
    const transaction = db.transaction(STORE_NAME, 'readwrite');
    transaction.objectStore(STORE_NAME).put(snapshot);

    transaction.oncomplete = () => resolve();
    transaction.onerror = () => reject(transaction.error || new Error('IndexedDB write failed'));
    transaction.onabort = () => reject(transaction.error || new Error('IndexedDB write aborted'));
  }).finally(() => db.close());
}

export default class extends Controller {
  static targets = ['count', 'items'];

  static values = {
    kind: Number,
    tags: Array,
    content: String,
    publishUrl: String,
    emptyMessage: String,
    itemSingular: String,
    itemPlural: String,
    removedMessage: String,
    removeFailedMessage: String,
    signerUnavailableMessage: String,
  };

  connect() {
    this.isSubmitting = false;
  }

  async remove(event) {
    event.preventDefault();
    event.stopPropagation();

    const button = event.currentTarget;
    const type = event.params.type;
    const value = event.params.value;

    if (
      this.isSubmitting
      || !type
      || !value
      || !this.hasPublishUrlValue
      || !this.publishUrlValue
    ) {
      return;
    }

    this.isSubmitting = true;
    button.disabled = true;
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');

    let signer;
    try {
      signer = await getSigner();
    } catch (error) {
      console.error('[bookmark-list] Signer unavailable:', error);
      this.toast(this.signerUnavailableMessageValue, 'danger');
      this.resetButton(button);
      return;
    }

    try {
      const pubkey = await signer.getPublicKey();
      const storedSnapshot = this.kindValue === STANDARD_BOOKMARK_KIND
        ? await readSnapshot(pubkey).catch(() => null)
        : null;
      const baseTags = storedSnapshot?.status === 'pending'
        ? normalizeTags(storedSnapshot.tags)
        : normalizeTags(this.tagsValue);
      const nextTags = removeTag(baseTags, type, value);

      const skeleton = {
        kind: this.kindValue,
        created_at: Math.floor(Date.now() / 1000),
        tags: nextTags,
        content: this.contentValue || '',
        pubkey,
      };
      const signedEvent = await signer.signEvent(skeleton);

      if (this.kindValue === STANDARD_BOOKMARK_KIND) {
        await writeSnapshot({
          pubkey,
          tags: nextTags,
          event: signedEvent,
          status: 'pending',
          retryCount: 0,
          nextRetryAt: null,
          lastError: null,
          updatedAt: Date.now(),
          lastSuccessAt: storedSnapshot?.lastSuccessAt || null,
        });
      }

      await this.postJSON(this.publishUrlValue, { event: signedEvent });

      this.tagsValue = nextTags;

      if (this.kindValue === STANDARD_BOOKMARK_KIND) {
        await writeSnapshot({
          pubkey,
          tags: nextTags,
          event: signedEvent,
          status: 'published',
          retryCount: 0,
          nextRetryAt: null,
          lastError: null,
          updatedAt: Date.now(),
          lastSuccessAt: Date.now(),
        });

        document.dispatchEvent(new CustomEvent('bookmark:state-changed', {
          detail: {
            coordinate: type === 'a' ? value : null,
            bookmarked: false,
            tags: nextTags,
          },
        }));
      }

      button.closest('[data-bookmark-list-item]')?.remove();
      this.updateListState();
      this.toast(this.removedMessageValue, 'success');
    } catch (error) {
      console.error('[bookmark-list] Removal failed:', error);
      this.toast(this.removeFailedMessageValue, 'danger');
      this.resetButton(button);
      return;
    }

    this.isSubmitting = false;
  }

  updateListState() {
    if (!this.hasItemsTarget) {
      return;
    }

    const remaining = this.itemsTarget.querySelectorAll('[data-bookmark-list-item]').length;

    if (this.hasCountTarget) {
      const label = remaining === 1 ? this.itemSingularValue : this.itemPluralValue;
      this.countTarget.textContent = `${remaining} ${label}`;
    }

    if (remaining === 0) {
      const emptyState = document.createElement('p');
      emptyState.className = 'empty-list text-muted';
      emptyState.textContent = this.emptyMessageValue;
      this.itemsTarget.replaceChildren(emptyState);
    }
  }

  resetButton(button) {
    this.isSubmitting = false;
    button.disabled = false;
    button.classList.remove('is-loading');
    button.removeAttribute('aria-busy');
  }

  async postJSON(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
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

  toast(message, type) {
    if (typeof window.showToast === 'function' && message) {
      window.showToast(message, type);
    }
  }
}
