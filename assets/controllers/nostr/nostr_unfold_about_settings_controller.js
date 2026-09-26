import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

export default class extends Controller {
  static targets = ['aboutArticle', 'saveButton', 'status', 'handoff', 'retryButton'];

  static values = {
    initialAbout: String,
    prepareUrl: String,
    commitUrl: String,
    coordinateAdminUrl: String,
    csrfToken: String,
    messages: Object,
  };

  connect() {
    try {
      const pending = sessionStorage.getItem(this.pendingStorageKey());
      if (pending) {
        this.pendingPayload = { ...JSON.parse(pending), _token: this.csrfTokenValue };
        this.showRelayPending();
      }
    } catch (_) {
      // The form still works when storage is unavailable.
    }
  }

  async submit(event) {
    const selection = this.aboutArticleTarget.value.trim();
    if (selection === this.initialAboutValue.trim()) {
      return;
    }

    event.preventDefault();
    if (this.saving) {
      return;
    }
    this.saving = true;
    this.saveButtonTarget.disabled = true;
    this.clearStatus();

    try {
      this.showStatus(this.message('preparing'));
      const prepared = await this.postJson(this.prepareUrlValue, {
        about_article: selection,
        _token: this.csrfTokenValue,
      });

      if (prepared.unchanged === true) {
        this.element.submit();
        return;
      }
      if (!prepared.event || typeof prepared.base_event_id !== 'string') {
        throw this.uiError('invalidResponse');
      }

      let signer;
      try {
        this.showStatus(this.message('connecting'));
        signer = await getSigner();
      } catch (_) {
        this.showSignerError(selection);
        return;
      }

      const signerPubkey = await signer.getPublicKey();
      if (signerPubkey.toLowerCase() !== prepared.event.pubkey?.toLowerCase()) {
        this.showHandoff(selection);
        throw this.uiError('wrongSigner');
      }

      this.showStatus(this.message('signing'));
      let signedEvent;
      try {
        signedEvent = await signer.signEvent(prepared.event);
      } catch (_) {
        throw this.uiError('signingRejected');
      }
      if (!signedEvent?.id || !signedEvent?.sig) {
        throw this.uiError('invalidSignature');
      }

      this.showStatus(this.message('publishing'));
      const commitPayload = {
        about_article: selection,
        base_event_id: prepared.base_event_id,
        event: signedEvent,
        _token: this.csrfTokenValue,
      };
      let committed = await this.postJson(this.commitUrlValue, commitPayload);
      if (committed.ok !== true) {
        throw this.uiError('failed');
      }
      if (committed.published === false) {
        this.storePending(commitPayload);
        try {
          committed = await this.postJson(this.commitUrlValue, commitPayload);
        } catch (_) {
          this.showRelayPending();
          return;
        }
        if (committed.ok !== true || committed.published === false) {
          this.showRelayPending();
          return;
        }
      }

      this.clearPending();
      this.showStatus(this.message('saving'));
      this.aboutArticleTarget.disabled = true;
      // Commit changed About. The regular form persists theme and footer links.
      this.element.submit();
    } catch (error) {
      this.showError(error.uiMessage || this.message('failed'));
    } finally {
      this.saving = false;
      this.saveButtonTarget.disabled = false;
    }
  }

  async retryPublish(event) {
    event.preventDefault();
    if (!this.pendingPayload || this.saving) {
      return;
    }
    this.saving = true;
    this.retryButtonTarget.disabled = true;
    this.saveButtonTarget.disabled = true;
    try {
      this.showStatus(this.message('publishing'));
      const committed = await this.postJson(this.commitUrlValue, this.pendingPayload);
      if (committed.ok !== true || committed.published === false) {
        this.showRelayPending();
        return;
      }
      this.clearPending();
      this.showStatus(this.message('saving'));
      this.aboutArticleTarget.disabled = true;
      this.element.submit();
    } catch (error) {
      if (error.uiMessage === this.message('stale')) {
        this.showError(error.uiMessage);
      } else {
        this.showRelayPending();
      }
    } finally {
      this.saving = false;
      this.retryButtonTarget.disabled = false;
      this.saveButtonTarget.disabled = false;
    }
  }

  async postJson(url, payload) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: JSON.stringify(payload),
    });
    if (!response.ok) {
      if (response.status === 409) throw this.uiError('stale');
      if (response.status === 422) throw this.uiError('invalidArticle');
      if (response.status === 403) throw this.uiError('accessDenied');
      throw this.uiError('failed');
    }
    return response.json().catch(() => { throw this.uiError('invalidResponse'); });
  }

  storePending(payload) {
    this.pendingPayload = payload;
    const { _token, ...stored } = payload;
    try { sessionStorage.setItem(this.pendingStorageKey(), JSON.stringify(stored)); } catch (_) {}
  }

  clearPending() {
    this.pendingPayload = null;
    try { sessionStorage.removeItem(this.pendingStorageKey()); } catch (_) {}
    this.retryButtonTarget.hidden = true;
  }

  pendingStorageKey() {
    return 'unfold-about-relay-pending:' + this.commitUrlValue;
  }

  showSignerError(selection) {
    this.showError(this.message('noSigner'));
    this.showHandoff(selection);
  }

  showHandoff(selection) {
    if (!this.hasHandoffTarget || !this.hasCoordinateAdminUrlValue) {
      return;
    }
    const url = new URL(this.coordinateAdminUrlValue, window.location.href);
    if (url.origin === window.location.origin) {
      return;
    }
    url.searchParams.set('about_article', selection);
    this.handoffTarget.href = url.toString();
    this.handoffTarget.hidden = false;
  }

  clearStatus() {
    this.statusTarget.textContent = '';
    this.statusTarget.hidden = true;
    this.handoffTarget.hidden = true;
    this.retryButtonTarget.hidden = true;
  }

  showStatus(message) {
    this.statusTarget.textContent = message;
    this.statusTarget.hidden = false;
    this.statusTarget.setAttribute('role', 'status');
  }

  showError(message) {
    this.showStatus(message);
    this.statusTarget.setAttribute('role', 'alert');
  }

  showRelayPending() {
    this.showError(this.message('relayPending'));
    this.retryButtonTarget.hidden = false;
  }

  message(key) {
    return this.messagesValue[key] || key;
  }

  uiError(key) {
    const error = new Error(key);
    error.uiMessage = this.message(key);
    return error;
  }
}