import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Short-lived relay AUTH polling controller.
 *
 * After login-triggered gateway warmup, this controller polls a tiny backend
 * endpoint for pending NIP-42 AUTH challenges, signs them, and submits them.
 * This avoids holding a reconnecting EventSource open in the browser.
 *
 * @stimulusFetch lazy
 */
export default class extends Controller {
  static values = {
    pubkey: String,
    pollUrl: String,
    pollInterval: { type: Number, default: 2000 },
    timeout: { type: Number, default: 45000 },
  };

  _processed = new Set();

  connect() {
    if (this._started) {
      return;
    }

    if (!this.pubkeyValue || !this.pollUrlValue) {
      console.debug('[relay-auth] Missing pubkey or poll URL, skipping');
      return;
    }

    this._started = true;
    console.debug('[relay-auth] Starting relay-auth polling window');
    this._poll();
    this._intervalId = window.setInterval(() => this._poll(), this.pollIntervalValue);
    this._timeoutId = window.setTimeout(() => {
      console.debug('[relay-auth] Poll window elapsed, stopping');
      this.disconnect();
    }, this.timeoutValue);
  }

  disconnect() {
    if (this._intervalId) {
      window.clearInterval(this._intervalId);
      this._intervalId = null;
    }
    if (this._timeoutId) {
      window.clearTimeout(this._timeoutId);
      this._timeoutId = null;
    }
    this._started = false;
  }

  async _poll() {
    if (this._pollInFlight) {
      return;
    }

    this._pollInFlight = true;
    try {
      const response = await fetch(this.pollUrlValue, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        return;
      }

      const payload = await response.json();
      const items = Array.isArray(payload.items) ? payload.items : [];
      if (items.length === 0) {
        return;
      }

      for (const item of items) {
        await this._handleChallenge(item);
      }
    } catch (e) {
      console.debug('[relay-auth] Poll failed', e);
    } finally {
      this._pollInFlight = false;
    }
  }

  async _handleChallenge(data) {
    const { requestId, relay, challenge } = data;

    if (!requestId || !relay || !challenge) {
      return;
    }

    if (this._processed.has(requestId)) {
      return;
    }
    this._processed.add(requestId);

    if (this._processed.size > 100) {
      const first = this._processed.values().next().value;
      this._processed.delete(first);
    }

    try {
      const authEvent = {
        kind: 22242,
        created_at: Math.floor(Date.now() / 1000),
        tags: [
          ['relay', relay],
          ['challenge', challenge],
        ],
        content: '',
      };

      const signer = await getSigner();
      const signed = await signer.signEvent(authEvent);

      const response = await fetch(`/api/relay-auth/${requestId}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ signedEvent: signed }),
      });

      if (!response.ok) {
        const errBody = await response.json().catch(() => ({}));
        console.warn('[relay-auth] API rejected signed event', {
          requestId,
          status: response.status,
          error: errBody.error,
        });
      }
    } catch (e) {
      console.error('[relay-auth] Failed to sign/submit AUTH', { requestId, relay, error: e.message });
    }
  }
}
