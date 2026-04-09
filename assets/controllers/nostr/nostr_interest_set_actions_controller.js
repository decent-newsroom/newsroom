import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Interest Set Actions Controller
 *
 * Handles Follow (add to kind 10015 "a" tags) and Clone (create own kind 30015)
 * for interest set boxes on the forum page.
 */
export default class extends Controller {
  static values = {
    publishUrl: String,     // Generic event publish endpoint
    tagsUrl: String,        // Endpoint to get current kind 10015 raw tags
    setPubkey: String,      // Pubkey of the interest set author
    setDtag: String,        // d-tag of the interest set
    setTitle: String,       // Title of the interest set
    setTags: Array,         // Array of tag strings in the set
    followed: Boolean,      // Whether this set is already followed
    owned: Boolean,         // Whether this set is authored by the current user
  };

  static targets = ['status'];

  /**
   * Follow: add this interest set as an "a" tag in the user's kind 10015 event.
   */
  async follow(event) {
    event.preventDefault();
    const btn = event.currentTarget;

    if (this.followedValue) {
      this._toast('This interest set is already in your interests.', 'info');
      return;
    }

    btn.disabled = true;
    btn.textContent = '…';

    try {
      const signer = await getSigner();
      const pubkey = await signer.getPublicKey();

      // Fetch current kind 10015 tags
      this._toast('Loading current interests…', 'info');
      const resp = await fetch(this.tagsUrlValue, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const data = await resp.json();
      const currentTags = data.tags || [];

      // Check if coordinate already exists
      const coordinate = `30015:${this.setPubkeyValue}:${this.setDtagValue}`;
      const alreadyFollowed = currentTags.some(
        t => Array.isArray(t) && t[0] === 'a' && t[1] === coordinate
      );

      if (alreadyFollowed) {
        this.followedValue = true;
        btn.textContent = '✓ Followed';
        this._toast('Already in your interests.', 'info');
        return;
      }

      // Append the new "a" tag
      const newTags = [...currentTags, ['a', coordinate]];

      // Build kind 10015 event skeleton
      const skeleton = {
        kind: 10015,
        created_at: Math.floor(Date.now() / 1000),
        tags: newTags,
        content: '',
        pubkey: pubkey,
      };

      this._toast('Requesting signature…', 'info');
      const signedEvent = await signer.signEvent(skeleton);

      this._toast('Publishing interests…', 'info');
      const result = await this._publish(signedEvent);

      if (result.success || result.status === 'ok') {
        this.followedValue = true;
        btn.textContent = '✓ Followed';
        this._toast('Interest set followed!', 'success');
        setTimeout(() => window.location.reload(), 1500);
      } else {
        throw new Error('Publishing returned no success');
      }
    } catch (e) {
      console.error('[interest-set-actions] Follow error:', e);
      this._toast(`Follow failed: ${e.message}`, 'danger');
      btn.textContent = 'Follow';
    } finally {
      btn.disabled = false;
    }
  }

  /**
   * Clone: create a new kind 30015 event with the same tags under the user's pubkey.
   */
  async clone(event) {
    event.preventDefault();
    const btn = event.currentTarget;
    btn.disabled = true;
    btn.textContent = '…';

    try {
      const signer = await getSigner();
      const pubkey = await signer.getPublicKey();

      // Build tags for the new kind 30015 event
      const dTag = `${this.setDtagValue}-${Date.now()}`;
      const tags = [
        ['d', dTag],
        ['title', `${this.setTitleValue}`],
        ...this.setTagsValue.map(t => ['t', t]),
      ];

      const skeleton = {
        kind: 30015,
        created_at: Math.floor(Date.now() / 1000),
        tags: tags,
        content: '',
        pubkey: pubkey,
      };

      this._toast('Requesting signature…', 'info');
      const signedEvent = await signer.signEvent(skeleton);

      this._toast('Publishing interest set…', 'info');
      const result = await this._publish(signedEvent);

      if (result.success || result.status === 'ok') {
        this._toast('Interest set cloned as your own!', 'success');
        setTimeout(() => window.location.reload(), 1500);
      } else {
        throw new Error('Publishing returned no success');
      }
    } catch (e) {
      console.error('[interest-set-actions] Clone error:', e);
      this._toast(`Clone failed: ${e.message}`, 'danger');
      btn.textContent = 'Clone as my set';
    } finally {
      btn.disabled = false;
    }
  }

  /**
   * Publish a signed event via the generic publish endpoint.
   */
  async _publish(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify({ event: signedEvent }),
    });

    if (!response.ok) {
      const errData = await response.json().catch(() => ({}));
      throw new Error(errData.error || `HTTP ${response.status}`);
    }

    return response.json();
  }

  _toast(message, type = 'info', duration = 4000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
    }
  }
}




