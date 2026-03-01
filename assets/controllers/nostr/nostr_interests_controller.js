import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Interests Editor Controller (NIP-51, kind 10015)
 *
 * Manages a tag selection UI for creating/editing the user's interests list.
 * Uses the shared signer flow to sign and publish the event.
 */
export default class extends Controller {
  static targets = ['publishButton', 'status', 'selectedList', 'customInput', 'tagChips', 'editor'];
  static values = {
    publishUrl: String,
    currentTags: Array,  // Tags the user already has in their interests
    popularTags: Array,  // All popular tags from ForumTopics
    groupedTags: Array,  // Tags grouped by category [{name, tags}]
  };

  connect() {
    console.log('[nostr-interests] Controller connected');
    // Initialize selected tags from current interests
    this.selectedTags = new Set((this.currentTagsValue || []).map(t => t.toLowerCase()));
    this.renderSelectedList();
    this.syncChipStates();
  }

  /**
   * Toggle a tag chip on/off
   */
  toggleTag(event) {
    const chip = event.currentTarget;
    const tag = chip.dataset.tag.toLowerCase();

    if (this.selectedTags.has(tag)) {
      this.selectedTags.delete(tag);
      chip.classList.remove('interests-chip--selected');
    } else {
      this.selectedTags.add(tag);
      chip.classList.add('interests-chip--selected');
    }
    this.renderSelectedList();
  }

  /**
   * Add a custom tag from the input
   */
  addCustomTag(event) {
    if (event.type === 'keydown' && event.key !== 'Enter') return;
    event.preventDefault();

    const input = this.customInputTarget;
    const raw = input.value.trim().toLowerCase().replace(/^#/, '');
    if (!raw) return;

    // Split by comma or space to allow adding multiple tags at once
    const tags = raw.split(/[,\s]+/).filter(t => t.length > 0);
    tags.forEach(tag => {
      this.selectedTags.add(tag);
      // If a chip for this tag exists, highlight it
      const chip = this.tagChipsTarget.querySelector(`[data-tag="${tag}"]`);
      if (chip) chip.classList.add('interests-chip--selected');
    });

    input.value = '';
    this.renderSelectedList();
  }

  /**
   * Remove a tag from the selected list
   */
  removeTag(event) {
    const tag = event.currentTarget.dataset.tag.toLowerCase();
    this.selectedTags.delete(tag);

    // Un-highlight the chip if it exists
    const chip = this.tagChipsTarget.querySelector(`[data-tag="${tag}"]`);
    if (chip) chip.classList.remove('interests-chip--selected');

    this.renderSelectedList();
  }

  /**
   * Toggle the editor panel visibility
   */
  toggleEditor() {
    this.editorTarget.classList.toggle('interests-editor--open');
  }

  /**
   * Sign and publish the interests event
   */
  async publish(event) {
    event.preventDefault();

    if (this.selectedTags.size === 0) {
      this.showError('Please select at least one tag.');
      return;
    }

    let signer;
    try {
      this.showStatus('Connecting to signer...');
      signer = await getSigner();
    } catch (e) {
      this.showError('No Nostr signer available. Please connect Amber or install a Nostr signer extension.');
      return;
    }

    this.publishButtonTarget.disabled = true;

    try {
      this.showStatus('Preparing interests event...');
      const pubkey = await signer.getPublicKey();

      // Build kind 10015 event skeleton
      const tags = Array.from(this.selectedTags).map(t => ['t', t]);
      const skeleton = {
        kind: 10015,
        created_at: Math.floor(Date.now() / 1000),
        tags: tags,
        content: '',
        pubkey: pubkey,
      };

      this.showStatus('Requesting signature from Nostr signer...');
      console.log('[nostr-interests] Signing event:', skeleton);
      const signedEvent = await signer.signEvent(skeleton);
      console.log('[nostr-interests] Event signed:', signedEvent);

      this.showStatus('Publishing interests...');
      const result = await this.sendToBackend(signedEvent);

      this.showSuccess('Interests published successfully! Refreshing...');
      console.log('[nostr-interests] Publish result:', result);

      // Reload page to re-fetch interests from relays
      setTimeout(() => {
        window.location.reload();
      }, 2000);

    } catch (error) {
      console.error('[nostr-interests] Publishing error:', error);
      this.showError(`Publishing failed: ${error.message}`);
    } finally {
      this.publishButtonTarget.disabled = false;
    }
  }

  /**
   * Send the signed event to the backend
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

  // ---------- UI Helpers ----------

  /**
   * Render the "selected tags" display
   */
  renderSelectedList() {
    const container = this.selectedListTarget;
    if (this.selectedTags.size === 0) {
      container.innerHTML = '<span class="text-muted">No tags selected</span>';
      return;
    }

    container.innerHTML = '';
    this.selectedTags.forEach(tag => {
      const pill = document.createElement('span');
      pill.className = 'interests-selected-tag';
      pill.innerHTML = `#${this.escapeHtml(tag)} <button type="button" data-action="click->nostr--nostr-interests#removeTag" data-tag="${this.escapeHtml(tag)}" aria-label="Remove ${this.escapeHtml(tag)}">&times;</button>`;
      container.appendChild(pill);
    });
  }

  /**
   * Sync chip highlighted states with selectedTags on connect
   */
  syncChipStates() {
    if (!this.hasTagChipsTarget) return;
    const chips = this.tagChipsTarget.querySelectorAll('[data-tag]');
    chips.forEach(chip => {
      if (this.selectedTags.has(chip.dataset.tag.toLowerCase())) {
        chip.classList.add('interests-chip--selected');
      }
    });
  }

  escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  showStatus(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'info', 3000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-info">${message}</div>`;
    }
  }

  showSuccess(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success', 4000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${message}</div>`;
    }
  }

  showError(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'danger', 8000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }
}

