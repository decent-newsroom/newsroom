import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Settings · Payment Targets (NIP-A3, kind 10133)
 *
 * Builds a `payto` payment-targets event from the dynamic editor rows,
 * signs it with the user's Nostr signer, and POSTs it to the shared
 * settings event-publish endpoint (`api_settings_event_publish`).
 *
 * Rules followed (NIP-A3):
 *  - `type` is lowercased and validated against `^[a-z0-9-]+$`.
 *  - `authority` is trimmed; empty rows are skipped silently.
 *  - Duplicate (type, authority) pairs are de-duplicated.
 *  - `content` is left empty per NIP-A3 examples.
 */
export default class extends Controller {
  static targets = ['list', 'row', 'typeInput', 'authorityInput', 'publishButton', 'empty'];

  static values = {
    publishUrl: String,
    targets: Array,
    recognized: Object,
  };

  connect() {
    // Ensure at least one editable row when the list is empty.
    if (!this.hasRowTarget) {
      this.appendRow('', '');
    }
  }

  addRow(event) {
    event?.preventDefault?.();
    this.appendRow('', '');
    const inputs = this.typeInputTargets;
    inputs[inputs.length - 1]?.focus();
  }

  removeRow(event) {
    event.preventDefault();
    const row = event.currentTarget.closest('[data-nostr--nostr-settings-payment-targets-target="row"]');
    if (!row) return;
    row.remove();

    if (!this.hasRowTarget) {
      this.appendRow('', '');
    }
  }

  appendRow(type, authority) {
    if (this.hasEmptyTarget) {
      this.emptyTarget.remove();
    }

    const row = document.createElement('div');
    row.className = 'payment-target-row settings-multi-field__row';
    row.setAttribute('data-nostr--nostr-settings-payment-targets-target', 'row');

    const typeInput = document.createElement('input');
    typeInput.type = 'text';
    typeInput.value = type;
    typeInput.placeholder = 'e.g. bitcoin, lightning, nano';
    typeInput.className = 'payment-target-row__type';
    typeInput.setAttribute('data-nostr--nostr-settings-payment-targets-target', 'typeInput');

    const authorityInput = document.createElement('input');
    authorityInput.type = 'text';
    authorityInput.value = authority;
    authorityInput.placeholder = 'e.g. you@walletofsatoshi.com or bc1q...';
    authorityInput.className = 'payment-target-row__authority';
    authorityInput.setAttribute('data-nostr--nostr-settings-payment-targets-target', 'authorityInput');

    const removeButton = document.createElement('button');
    removeButton.type = 'button';
    removeButton.className = 'btn btn--secondary settings-multi-field__remove';
    removeButton.setAttribute('data-action', 'click->nostr--nostr-settings-payment-targets#removeRow');
    removeButton.textContent = '−';

    row.append(typeInput, authorityInput, removeButton);
    this.listTarget.appendChild(row);
  }

  collectTags() {
    const tags = [];
    const seen = new Set();
    const typeRegex = /^[a-z0-9-]+$/;

    const typeInputs = this.typeInputTargets;
    const authorityInputs = this.authorityInputTargets;

    for (let i = 0; i < typeInputs.length; i++) {
      const type = (typeInputs[i].value || '').trim().toLowerCase();
      const authority = (authorityInputs[i]?.value || '').trim();
      if (!type || !authority) continue;
      if (!typeRegex.test(type)) continue;

      const key = `${type}|${authority}`;
      if (seen.has(key)) continue;
      seen.add(key);

      tags.push(['payto', type, authority]);
    }

    return tags;
  }

  async publish(event) {
    event?.preventDefault?.();

    const tags = this.collectTags();
    if (tags.length === 0) {
      this.toast('Add at least one payment target before publishing.', 'danger');
      return;
    }

    let signer;
    try {
      this.toast('Connecting to signer...', 'info');
      signer = await getSigner();
    } catch (e) {
      this.toast('No Nostr signer available. Please connect a signer extension.', 'danger');
      return;
    }

    if (this.hasPublishButtonTarget) this.publishButtonTarget.disabled = true;

    try {
      const pubkey = await signer.getPublicKey();
      const skeleton = {
        kind: 10133,
        created_at: Math.floor(Date.now() / 1000),
        tags,
        content: '',
        pubkey,
      };

      this.toast('Requesting signature...', 'info');
      const signedEvent = await signer.signEvent(skeleton);

      this.toast('Publishing payment targets...', 'info');
      const result = await this.sendToBackend(signedEvent);

      if (result.success) {
        this.toast(`Payment targets published! (${result.relays_success} relay${result.relays_success !== 1 ? 's' : ''})`, 'success');
        setTimeout(() => window.location.reload(), 1500);
      } else {
        this.toast('Publishing failed — no relays accepted the event.', 'danger');
      }
    } catch (err) {
      console.error('[settings-payment-targets] publish error', err);
      this.toast(`Publishing failed: ${err.message}`, 'danger');
    } finally {
      if (this.hasPublishButtonTarget) this.publishButtonTarget.disabled = false;
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

  toast(message, type = 'info') {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, 4000);
    }
  }
}

