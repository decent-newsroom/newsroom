import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

/**
 * Article Actions Dropdown Controller
 *
 * Consolidates share (copy), bookmark, broadcast, and highlights toggle
 * into a single dropdown. Keeps the action bar clean by grouping secondary
 * actions behind a kebab menu (⋮).
 */
export default class extends Controller {
  static targets = [
    'trigger', 'menu', 'status',
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
    this.menuOpen = false;
    this._closeOnOutsideClick = this._closeOnOutsideClick.bind(this);

    // Fetch bookmark state if user is logged in
    if (this.hasBookmarkFetchUrlValue && this.bookmarkFetchUrlValue) {
      this.fetchBookmarkState();
    }
  }

  disconnect() {
    document.removeEventListener('click', this._closeOnOutsideClick);
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
      this.showStatus('Copied!', 'success');
      this.close();
    }).catch(() => {
      this.showStatus('Failed to copy', 'error');
    });
  }

  // ── Bookmark ────────────────────────────────────────────────

  async fetchBookmarkState() {
    try {
      const response = await fetch(this.bookmarkFetchUrlValue, {
        headers: { 'Accept': 'application/json' },
      });
      if (!response.ok) return;

      const data = await response.json();
      this.bookmarkTags = data.tags || [];

      this.isBookmarked = this.bookmarkTags.some(
        tag => Array.isArray(tag) && tag[0] === 'a' && tag[1] === this.coordinateValue
      );

      this.updateBookmarkUI();
    } catch (e) {
      console.warn('[article-actions] Failed to fetch bookmark state:', e);
    }
  }

  async toggleBookmark(event) {
    event.preventDefault();
    this.close();

    if (!this.hasBookmarkPublishUrlValue) return;

    let signer;
    try {
      this.showStatus('Connecting to signer…', 'info');
      signer = await getSigner();
    } catch (e) {
      this.showStatus('No Nostr signer available', 'error');
      return;
    }

    try {
      const pubkey = await signer.getPublicKey();

      let newTags;
      if (this.isBookmarked) {
        newTags = this.bookmarkTags.filter(
          tag => !(Array.isArray(tag) && tag[0] === 'a' && tag[1] === this.coordinateValue)
        );
        this.showStatus('Removing bookmark…', 'info');
      } else {
        newTags = [...this.bookmarkTags, ['a', this.coordinateValue]];
        this.showStatus('Adding bookmark…', 'info');
      }

      const skeleton = {
        kind: 10003,
        created_at: Math.floor(Date.now() / 1000),
        tags: newTags,
        content: '',
        pubkey,
      };

      this.showStatus('Requesting signature…', 'info');
      const signedEvent = await signer.signEvent(skeleton);

      this.showStatus('Publishing…', 'info');
      await this.postJSON(this.bookmarkPublishUrlValue, { event: signedEvent });

      this.isBookmarked = !this.isBookmarked;
      this.bookmarkTags = newTags;
      this.updateBookmarkUI();

      const action = this.isBookmarked ? 'added' : 'removed';
      this.showStatus(`Bookmark ${action}!`, 'success');
    } catch (error) {
      console.error('[article-actions] Bookmark error:', error);
      this.showStatus(`Failed: ${error.message}`, 'error');
    }
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
    }
  }

  // ── Broadcast ───────────────────────────────────────────────

  async broadcast(event) {
    event.preventDefault();
    this.close();

    const btn = event.currentTarget;
    const coordinate = btn.dataset.coordinate;
    const articleId = btn.dataset.articleId;
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
        const msg = `Broadcast to ${data.broadcast.successful}/${data.broadcast.total_relays} relays`;
        this.showStatus(msg, 'success');
        if (window.showToast) window.showToast(msg, 'success', 5000);
      } else {
        throw new Error(data.error || 'Broadcast failed');
      }
    } catch (error) {
      console.error('[article-actions] Broadcast error:', error);
      const msg = `Broadcast failed: ${error.message}`;
      this.showStatus(msg, 'error');
      if (window.showToast) window.showToast(msg, 'danger', 5000);
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

  showStatus(message, type = 'info') {
    if (!this.hasStatusTarget) return;
    this.statusTarget.textContent = message;
    this.statusTarget.className = `article-actions-status article-actions-status--${type}`;

    if (type === 'success' || type === 'info') {
      clearTimeout(this._statusTimeout);
      this._statusTimeout = setTimeout(() => {
        if (this.hasStatusTarget) this.statusTarget.textContent = '';
      }, 3000);
    }
    if (type === 'error') {
      clearTimeout(this._statusTimeout);
      this._statusTimeout = setTimeout(() => {
        if (this.hasStatusTarget) this.statusTarget.textContent = '';
      }, 5000);
    }
  }
}

