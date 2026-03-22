import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Bookmark Controller (NIP-51, kind 10003)
 *
 * Manages bookmarking articles. On connect, fetches the user's current
 * bookmarks to determine if the article is already bookmarked.
 * On toggle, builds a new kind 10003 event with the article's coordinate
 * added or removed, signs it, and publishes to relays via the backend.
 */
export default class extends Controller {
  static targets = ['button', 'icon', 'label', 'status'];
  static values = {
    coordinate: String,   // e.g. "30023:<pubkey>:<slug>"
    fetchUrl: String,     // GET /api/bookmarks/current
    publishUrl: String,   // POST /api/bookmarks/publish
  };

  connect() {
    this.isBookmarked = false;
    this.currentTags = [];
    this.loading = false;

    // Fetch current bookmark state
    this.fetchCurrentBookmarks();
  }

  /**
   * Fetch the user's current kind 10003 tags from the backend.
   */
  async fetchCurrentBookmarks() {
    try {
      const response = await fetch(this.fetchUrlValue, {
        headers: { 'Accept': 'application/json' },
      });

      if (!response.ok) return;

      const data = await response.json();
      this.currentTags = data.tags || [];

      // Check if this article's coordinate is already bookmarked
      this.isBookmarked = this.currentTags.some(
        tag => Array.isArray(tag) && tag[0] === 'a' && tag[1] === this.coordinateValue
      );

      this.updateUI();
    } catch (e) {
      console.warn('[nostr-bookmark] Failed to fetch bookmarks:', e);
    }
  }

  /**
   * Toggle bookmark state: add or remove the article coordinate.
   */
  async toggle(event) {
    event.preventDefault();

    if (this.loading) return;
    this.loading = true;

    let signer;
    try {
      this.showStatus('Connecting to signer…');
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please install a Nostr signer extension.');
      this.loading = false;
      return;
    }

    try {
      const pubkey = await signer.getPublicKey();

      // Build new tags list: add or remove the article coordinate
      let newTags;
      if (this.isBookmarked) {
        // Remove: filter out the matching 'a' tag
        newTags = this.currentTags.filter(
          tag => !(Array.isArray(tag) && tag[0] === 'a' && tag[1] === this.coordinateValue)
        );
        this.showStatus('Removing bookmark…');
      } else {
        // Add: append the 'a' tag
        newTags = [...this.currentTags, ['a', this.coordinateValue]];
        this.showStatus('Adding bookmark…');
      }

      // Build kind 10003 event skeleton
      const skeleton = {
        kind: 10003,
        created_at: Math.floor(Date.now() / 1000),
        tags: newTags,
        content: '',
        pubkey: pubkey,
      };

      this.showStatus('Requesting signature…');
      const signedEvent = await signer.signEvent(skeleton);

      this.showStatus('Publishing…');
      const result = await this.sendToBackend(signedEvent);

      // Update state
      this.isBookmarked = !this.isBookmarked;
      this.currentTags = newTags;
      this.updateUI();

      const action = this.isBookmarked ? 'added' : 'removed';
      this.showSuccess(`Bookmark ${action}!`);

      console.log('[nostr-bookmark] Publish result:', result);
    } catch (error) {
      console.error('[nostr-bookmark] Error:', error);
      this.showError(`Failed: ${error.message}`);
    } finally {
      this.loading = false;
    }
  }

  /**
   * Send the signed event to the backend publish endpoint.
   */
  async sendToBackend(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ event: signedEvent }),
    });

    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return response.json();
  }

  // ── UI Helpers ──────────────────────────────────────────────

  updateUI() {
    if (this.hasIconTarget) {
      const svg = this.iconTarget;
      if (this.isBookmarked) {
        svg.setAttribute('fill', 'currentColor');
        svg.classList.add('bookmark-btn--active');
      } else {
        svg.setAttribute('fill', 'none');
        svg.classList.remove('bookmark-btn--active');
      }
    }

    if (this.hasLabelTarget) {
      this.labelTarget.textContent = this.isBookmarked ? 'Bookmarked' : 'Bookmark';
    }

    if (this.hasButtonTarget) {
      this.buttonTarget.title = this.isBookmarked ? 'Remove bookmark' : 'Add bookmark';
      this.buttonTarget.classList.toggle('bookmark-btn--bookmarked', this.isBookmarked);
    }
  }

  showStatus(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      this.statusTarget.className = 'bookmark-status bookmark-status--info';
    }
  }

  showSuccess(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      this.statusTarget.className = 'bookmark-status bookmark-status--success';
      setTimeout(() => {
        if (this.hasStatusTarget) this.statusTarget.textContent = '';
      }, 3000);
    }
  }

  showError(message) {
    if (this.hasStatusTarget) {
      this.statusTarget.textContent = message;
      this.statusTarget.className = 'bookmark-status bookmark-status--error';
      setTimeout(() => {
        if (this.hasStatusTarget) this.statusTarget.textContent = '';
      }, 5000);
    }
    this.loading = false;
  }
}

