import { Controller } from '@hotwired/stimulus';
import { getSigner } from '../nostr/signer_manager.js';

export default class extends Controller {
  static values = {
    publishUrl: String,
    eventId: String,
    coordinate: String,
    articleTitle: String,
  };

  async requestDelete(event) {
    event.preventDefault();

    if (!this.hasEventIdValue || !this.eventIdValue) {
      this._toast('Missing article event id.', 'danger');
      return;
    }

    const title = this.articleTitleValue || 'this article';
    const confirmed = window.confirm(`Send a NIP-09 delete request for "${title}"?`);
    if (!confirmed) {
      return;
    }

    this.element.disabled = true;

    try {
      this._toast('Connecting to signer...', 'info');
      const signer = await getSigner();
      const pubkey = await signer.getPublicKey();

      const tags = [['e', this.eventIdValue]];
      if (this.hasCoordinateValue && this.coordinateValue) {
        tags.push(['a', this.coordinateValue]);
      }

      const skeleton = {
        kind: 5,
        created_at: Math.floor(Date.now() / 1000),
        tags,
        content: `Delete request for ${title}`,
        pubkey,
      };

      this._toast('Requesting signature...', 'info');
      const signedEvent = await signer.signEvent(skeleton);

      this._toast('Publishing delete request...', 'info');
      const result = await this._publish(signedEvent);

      if (!(result.success || result.status === 'ok')) {
        throw new Error('Delete request was not accepted by relays');
      }

      this._toast('Delete request published.', 'success');
      this.element.textContent = 'del';
      this.element.title = 'Delete request sent';
      this.element.disabled = true;
    } catch (e) {
      console.error('[my-content-delete] Failed to publish delete request:', e);
      this._toast(`Delete request failed: ${e.message}`, 'danger');
      this.element.disabled = false;
    }
  }

  async _publish(signedEvent) {
    const response = await fetch(this.publishUrlValue, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
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

  _toast(message, type = 'info', duration = 4000) {
    if (typeof window.showToast === 'function') {
      window.showToast(message, type, duration);
    }
  }
}


