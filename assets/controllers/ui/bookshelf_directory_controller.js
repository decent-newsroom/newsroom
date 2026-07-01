import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

const DIRECTORY_KIND = 30045;
const MAX_ITEMS = 500;

function normalizeEventId(value) {
  return typeof value === 'string' && /^[a-f0-9]{64}$/i.test(value) ? value.toLowerCase() : null;
}

function normalizeCoordinate(value) {
  if (typeof value !== 'string') {
    return null;
  }

  const parts = value.split(':', 3);
  if (parts.length !== 3 || !/^\d+$/.test(parts[0]) || !/^[a-f0-9]{64}$/i.test(parts[1]) || !parts[2]) {
    return null;
  }

  return `${parseInt(parts[0], 10)}:${parts[1].toLowerCase()}:${parts[2]}`;
}

function normalizeRelay(value) {
  if (typeof value !== 'string' || value.trim() === '') {
    return '';
  }

  try {
    const parsed = new URL(value);
    return ['ws:', 'wss:'].includes(parsed.protocol) ? value : '';
  } catch {
    return '';
  }
}

function normalizeTags(tags, identifier) {
  const normalized = [['d', identifier]];
  const seen = new Set();

  if (!Array.isArray(tags)) {
    return normalized;
  }

  for (const tag of tags) {
    if (!Array.isArray(tag) || typeof tag[0] !== 'string') {
      continue;
    }

    if (tag[0] !== 'a') {
      continue;
    }

    const coordinate = normalizeCoordinate(tag[1]);
    if (!coordinate || seen.has(coordinate)) {
      continue;
    }

    seen.add(coordinate);

    const relay = normalizeRelay(tag[2]);
    const eventId = normalizeEventId(tag[3]);
    const nextTag = ['a', coordinate];

    if (relay !== '' || eventId !== null) {
      nextTag.push(relay);
    }
    if (eventId !== null) {
      nextTag.push(eventId);
    }

    normalized.push(nextTag);

    if (normalized.length > MAX_ITEMS) {
      break;
    }
  }

  return normalized;
}

export default class extends Controller {
  static targets = ['toggle'];

  static values = {
    publishUrl: String,
    csrfToken: String,
    directoryIdentifier: String,
    initialTags: Array,
    addedMessage: String,
    removedMessage: String,
    publishFailedMessage: String,
    signerUnavailableMessage: String,
    limitReachedMessage: String,
  };

  connect() {
    this.isSubmitting = false;
    this.tags = normalizeTags(this.initialTagsValue, this.identifier());
    this.syncButtons();
  }

  async toggle(event) {
    event.preventDefault();

    if (this.isSubmitting || !this.hasPublishUrlValue || !this.publishUrlValue) {
      return;
    }

    const button = event.currentTarget;
    const coordinate = normalizeCoordinate(event.params.coordinate);
    if (!coordinate) {
      return;
    }

    const isSaved = this.hasCoordinate(coordinate);
    if (!isSaved && this.tags.length - 1 >= MAX_ITEMS) {
      this.toast(this.limitReachedMessageValue || `Directory limit reached (${MAX_ITEMS}).`, 'warning');
      return;
    }

    this.isSubmitting = true;
    this.setButtonBusy(button, true);

    const relay = normalizeRelay(event.params.relay);
    const eventId = normalizeEventId(event.params.eventId);

    const nextTags = isSaved
      ? this.removeCoordinate(this.tags, coordinate)
      : this.addCoordinate(this.tags, coordinate, relay, eventId);

    try {
      const signer = await getSigner();
      const pubkey = await signer.getPublicKey();
      const skeleton = {
        kind: DIRECTORY_KIND,
        created_at: Math.floor(Date.now() / 1000),
        tags: nextTags,
        content: '',
        pubkey,
      };

      const signedEvent = await signer.signEvent(skeleton);
      await this.postJSON(this.publishUrlValue, { event: signedEvent });

      this.tags = nextTags;
      this.syncButtons();
      this.updateInventory();
      this.toast(isSaved ? this.removedMessageValue : this.addedMessageValue, 'success');
    } catch (error) {
      console.error('[bookshelf-directory] Publish failed:', error);
      const message = /signer/i.test(String(error?.message || ''))
        ? this.signerUnavailableMessageValue
        : this.publishFailedMessageValue;
      this.toast(message || 'Could not update My Books.', 'danger');
    }

    this.setButtonBusy(button, false);
    this.isSubmitting = false;
  }

  identifier() {
    return this.directoryIdentifierValue || 'my-book-collection';
  }

  hasCoordinate(coordinate) {
    return this.tags.some((tag) => tag[0] === 'a' && tag[1] === coordinate);
  }

  addCoordinate(tags, coordinate, relay, eventId) {
    const next = this.removeCoordinate(tags, coordinate);
    const tag = ['a', coordinate];

    if (relay !== '' || eventId !== null) {
      tag.push(relay);
    }

    if (eventId !== null) {
      tag.push(eventId);
    }

    next.push(tag);

    return next;
  }

  removeCoordinate(tags, coordinate) {
    const identifier = this.identifier();

    return tags.filter((tag) => {
      if (!Array.isArray(tag) || typeof tag[0] !== 'string') {
        return false;
      }

      if (tag[0] === 'd') {
        return tag[1] === identifier;
      }

      return !(tag[0] === 'a' && tag[1] === coordinate);
    });
  }

  syncButtons() {
    for (const button of this.toggleTargets) {
      const coordinate = normalizeCoordinate(button.dataset.uiBookshelfDirectoryCoordinateParam);
      if (!coordinate) {
        continue;
      }

      const inDirectory = this.hasCoordinate(coordinate);
      button.dataset.inDirectory = inDirectory ? 'true' : 'false';
      button.setAttribute('aria-pressed', inDirectory ? 'true' : 'false');
      button.classList.toggle('is-active', inDirectory);

      const label = inDirectory ? button.dataset.labelRemove : button.dataset.labelAdd;
      if (label) {
        button.textContent = label;
      }
    }
  }

  updateInventory() {
    const allRows = this.element.querySelectorAll('[data-ui--bookshelf-directory-item]');
    for (const row of allRows) {
      const coordinate = row.getAttribute('data-ui--bookshelf-directory-item');
      if (coordinate && !this.hasCoordinate(coordinate)) {
        row.remove();
      }
    }
  }

  setButtonBusy(button, state) {
    button.disabled = state;
    button.classList.toggle('is-loading', state);
    if (state) {
      button.setAttribute('aria-busy', 'true');
      return;
    }

    button.removeAttribute('aria-busy');
  }

  async postJSON(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': this.csrfTokenValue || '',
      },
      body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));
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


