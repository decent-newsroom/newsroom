import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Settings Relay List Editor Controller (NIP-65, kind 10002)
 *
 * Manages the user's relay list: add/remove relays, toggle read/write markers,
 * sign as a kind 10002 event, and publish to relays via the backend.
 */
export default class extends Controller {
  static targets = ['relayList', 'urlInput', 'publishButton', 'emptyState'];

  static values = {
    publishUrl: String,
    relays: Array, // [{url, read, write}]
    homeRelay: String, // project relay URL from backend config
  };

  connect() {
    console.log('[settings-relays] Controller connected');
    // Initialize working copy from the value
    this.relayEntries = (this.relaysValue || []).map(r => ({
      url: r.url,
      read: r.read !== false,
      write: r.write !== false,
    }));
    this.render();
  }

  /**
   * Add a new relay URL from the input field.
   */
  addRelay(event) {
    if (event) event.preventDefault();

    const input = this.urlInputTarget;
    let url = input.value.trim();
    if (!url) return;

    // Auto-prepend wss:// if user typed bare domain
    if (!url.startsWith('wss://') && !url.startsWith('ws://')) {
      url = 'wss://' + url;
    }

    // Validate
    if (!url.startsWith('wss://')) {
      this.showError('Relay URL must start with wss://');
      return;
    }

    // Ensure trailing slash consistency — normalise to no trailing slash
    url = url.replace(/\/+$/, '');

    // Check duplicates
    if (this.relayEntries.some(r => r.url.replace(/\/+$/, '') === url)) {
      this.showError('This relay is already in your list.');
      return;
    }

    this.relayEntries.push({ url, read: true, write: true });
    input.value = '';
    this.render();
  }

  /**
   * Add the project home relay with one click.
   * The URL comes from the backend via the homeRelay Stimulus value,
   * so it stays in sync with relay_registry.project_relays in services.yaml.
   */
  addHomeRelay(event) {
    if (event) event.preventDefault();

    const url = this.homeRelayValue;
    if (!url) {
      this.showError('Home relay URL not configured.');
      return;
    }

    if (this.relayEntries.some(r => r.url.replace(/\/+$/, '') === url.replace(/\/+$/, ''))) {
      this.showError('This relay is already in your list.');
      return;
    }

    this.relayEntries.push({ url, read: true, write: true });
    this.render();
  }

  /**
   * Handle Enter key in the URL input.
   */
  addRelayOnEnter(event) {
    if (event.key === 'Enter') {
      event.preventDefault();
      this.addRelay();
    }
  }

  /**
   * Remove a relay by index.
   */
  removeRelay(event) {
    const index = parseInt(event.currentTarget.dataset.index, 10);
    if (index >= 0 && index < this.relayEntries.length) {
      this.relayEntries.splice(index, 1);
      this.render();
    }
  }

  /**
   * Toggle the read marker for a relay.
   */
  toggleRead(event) {
    const index = parseInt(event.currentTarget.dataset.index, 10);
    if (index >= 0 && index < this.relayEntries.length) {
      this.relayEntries[index].read = event.currentTarget.checked;
    }
  }

  /**
   * Toggle the write marker for a relay.
   */
  toggleWrite(event) {
    const index = parseInt(event.currentTarget.dataset.index, 10);
    if (index >= 0 && index < this.relayEntries.length) {
      this.relayEntries[index].write = event.currentTarget.checked;
    }
  }

  /**
   * Sign and publish the relay list as a kind 10002 event.
   */
  async publish(event) {
    event.preventDefault();

    if (this.relayEntries.length === 0) {
      this.showError('Add at least one relay before publishing.');
      return;
    }

    // Validate: every relay must be read, write, or both
    const invalid = this.relayEntries.filter(r => !r.read && !r.write);
    if (invalid.length > 0) {
      this.showError('Each relay must be marked as read, write, or both.');
      return;
    }

    let signer;
    try {
      this.showStatus('Connecting to signer...');
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please connect a signer extension.');
      return;
    }

    if (this.hasPublishButtonTarget) {
      this.publishButtonTarget.disabled = true;
    }

    try {
      this.showStatus('Preparing relay list event...');
      const pubkey = await signer.getPublicKey();

      // Build kind 10002 tags from relay entries
      const tags = this.relayEntries.map(relay => {
        if (relay.read && relay.write) {
          return ['r', relay.url]; // No marker = both
        } else if (relay.read) {
          return ['r', relay.url, 'read'];
        } else {
          return ['r', relay.url, 'write'];
        }
      });

      const skeleton = {
        kind: 10002,
        created_at: Math.floor(Date.now() / 1000),
        tags: tags,
        content: '',
        pubkey: pubkey,
      };

      this.showStatus('Requesting signature from Nostr signer...');
      console.log('[settings-relays] Signing event:', skeleton);
      const signedEvent = await signer.signEvent(skeleton);
      console.log('[settings-relays] Event signed:', signedEvent);

      this.showStatus('Publishing relay list...');
      const result = await this.sendToBackend(signedEvent);

      if (result.success) {
        this.showSuccess(`Relay list published! (${result.relays_success} relay${result.relays_success !== 1 ? 's' : ''})`);
        setTimeout(() => window.location.reload(), 2000);
      } else {
        this.showError('Publishing failed — no relays accepted the event.');
      }
    } catch (error) {
      console.error('[settings-relays] Publishing error:', error);
      this.showError(`Publishing failed: ${error.message}`);
    } finally {
      if (this.hasPublishButtonTarget) {
        this.publishButtonTarget.disabled = false;
      }
    }
  }

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

  // ---------- Rendering ----------

  render() {
    if (!this.hasRelayListTarget) return;

    if (this.relayEntries.length === 0) {
      this.relayListTarget.innerHTML = '';
      if (this.hasEmptyStateTarget) {
        this.emptyStateTarget.classList.remove('hidden');
      }
      return;
    }

    if (this.hasEmptyStateTarget) {
      this.emptyStateTarget.classList.add('hidden');
    }

    const ctrl = this.identifier; // e.g. "nostr--nostr-settings-relays"

    this.relayListTarget.innerHTML = this.relayEntries.map((relay, i) => `
      <div class="relay-row">
        <div class="relay-row__url">
          <code>${this.escapeHtml(relay.url)}</code>
        </div>
        <div class="relay-row__markers">
          <label class="relay-row__marker">
            <input type="checkbox"
                   ${relay.read ? 'checked' : ''}
                   data-index="${i}"
                   data-action="change->${ctrl}#toggleRead">
            Read
          </label>
          <label class="relay-row__marker">
            <input type="checkbox"
                   ${relay.write ? 'checked' : ''}
                   data-index="${i}"
                   data-action="change->${ctrl}#toggleWrite">
            Write
          </label>
        </div>
        <button type="button" class="relay-row__remove"
                data-index="${i}"
                data-action="click->${ctrl}#removeRelay"
                title="Remove relay">✕</button>
      </div>
    `).join('');
  }

  // ---------- Status Helpers ----------

  showStatus(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'info', 3000);
    }
  }

  showSuccess(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success', 4000);
    }
  }

  showError(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'danger', 8000);
    }
  }

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
}

