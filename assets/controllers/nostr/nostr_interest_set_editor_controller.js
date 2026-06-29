import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

export default class extends Controller {
  static targets = ['publishButton', 'status', 'selectedList', 'customInput', 'tagChips', 'titleInput'];

  static values = {
    publishUrl: String,
    redirectUrl: String,
    setPubkey: String,
    setDtag: String,
    currentTags: Array,
    messages: Object,
  };

  connect() {
    this.selectedTags = new Set((this.currentTagsValue || []).map((tag) => this.normalizeTag(tag)));
    this.selectedTags.delete('');
    this.renderSelectedList();
    this.syncChipStates();
  }

  toggleTag(event) {
    const chip = event.currentTarget;
    const tag = this.normalizeTag(chip.dataset.tag || '');

    if (!tag) {
      return;
    }

    if (this.selectedTags.has(tag)) {
      this.selectedTags.delete(tag);
      chip.classList.remove('interests-chip--selected');
    } else {
      this.selectedTags.add(tag);
      chip.classList.add('interests-chip--selected');
    }

    this.renderSelectedList();
  }

  addCustomTag(event) {
    if (event.type === 'keydown' && event.key !== 'Enter') {
      return;
    }

    event.preventDefault();

    const raw = this.customInputTarget.value.trim();
    if (!raw) {
      return;
    }

    raw.split(/[,\s]+/)
      .map((tag) => this.normalizeTag(tag))
      .filter((tag) => tag.length > 0)
      .forEach((tag) => {
        this.selectedTags.add(tag);
        this.findChip(tag)?.classList.add('interests-chip--selected');
      });

    this.customInputTarget.value = '';
    this.renderSelectedList();
  }

  removeTag(event) {
    const tag = this.normalizeTag(event.currentTarget.dataset.tag || '');
    this.selectedTags.delete(tag);
    this.findChip(tag)?.classList.remove('interests-chip--selected');
    this.renderSelectedList();
  }

  async publish(event) {
    event.preventDefault();

    const title = this.titleInputTarget.value.trim();
    if (!title) {
      this.showError(this.message('titleRequired', 'Please add a title.'));
      return;
    }

    if (this.selectedTags.size === 0) {
      this.showError(this.message('tagRequired', 'Please select at least one tag.'));
      return;
    }

    let signer;
    try {
      this.showStatus(this.message('signerConnecting', 'Connecting to signer...'));
      signer = await getSigner();
    } catch {
      this.showError(this.message('signerUnavailable', 'No Nostr signer available.'));
      return;
    }

    this.publishButtonTarget.disabled = true;

    try {
      const pubkey = await signer.getPublicKey();
      if (this.hasSetPubkeyValue && this.setPubkeyValue && pubkey !== this.setPubkeyValue) {
        throw new Error(this.message('signerMismatch', 'The connected signer does not own this interest set.'));
      }

      this.showStatus(this.message('preparing', 'Preparing interest set...'));
      const tags = [
        ['d', this.setDtagValue],
        ['title', title],
        ...Array.from(this.selectedTags).sort().map((tag) => ['t', tag]),
      ];

      const skeleton = {
        kind: 30015,
        created_at: Math.floor(Date.now() / 1000),
        tags,
        content: '',
        pubkey,
      };

      this.showStatus(this.message('signing', 'Requesting signature...'));
      const signedEvent = await signer.signEvent(skeleton);

      this.showStatus(this.message('publishing', 'Publishing interest set...'));
      await this.sendToBackend(signedEvent);

      this.showSuccess(this.message('success', 'Interest set published. Refreshing...'));
      window.setTimeout(() => {
        window.location.href = this.redirectUrlValue || window.location.href;
      }, 1200);
    } catch (error) {
      console.error('[interest-set-editor] Publishing error:', error);
      this.showError(`${this.message('failed', 'Publishing failed')}: ${error.message}`);
    } finally {
      this.publishButtonTarget.disabled = false;
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

  renderSelectedList() {
    const container = this.selectedListTarget;
    if (this.selectedTags.size === 0) {
      container.innerHTML = `<span class="text-muted">${this.escapeHtml(this.message('noTagsSelected', 'No tags selected'))}</span>`;
      return;
    }

    container.innerHTML = '';
    Array.from(this.selectedTags).sort().forEach((tag) => {
      const pill = document.createElement('span');
      pill.className = 'interests-selected-tag';
      pill.innerHTML = `#${this.escapeHtml(tag)} <button type="button" data-action="click->nostr--nostr-interest-set-editor#removeTag" data-tag="${this.escapeHtml(tag)}" aria-label="${this.escapeHtml(this.message('removeTag', 'Remove tag'))}: ${this.escapeHtml(tag)}">&times;</button>`;
      container.appendChild(pill);
    });
  }

  syncChipStates() {
    if (!this.hasTagChipsTarget) {
      return;
    }

    this.tagChipsTarget.querySelectorAll('[data-tag]').forEach((chip) => {
      chip.classList.toggle('interests-chip--selected', this.selectedTags.has(this.normalizeTag(chip.dataset.tag || '')));
    });
  }

  findChip(tag) {
    if (!this.hasTagChipsTarget) {
      return null;
    }

    return Array.from(this.tagChipsTarget.querySelectorAll('[data-tag]'))
      .find((chip) => this.normalizeTag(chip.dataset.tag || '') === tag) || null;
  }

  normalizeTag(tag) {
    return String(tag || '').trim().toLowerCase().replace(/^#/, '');
  }

  message(key, fallback) {
    return this.messagesValue?.[key] || fallback;
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
      this.statusTarget.innerHTML = `<div class="alert alert-info">${this.escapeHtml(message)}</div>`;
    }
  }

  showSuccess(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'success', 4000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-success">${this.escapeHtml(message)}</div>`;
    }
  }

  showError(message) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, 'danger', 8000);
    } else if (this.hasStatusTarget) {
      this.statusTarget.innerHTML = `<div class="alert alert-danger">${this.escapeHtml(message)}</div>`;
    }
  }
}
