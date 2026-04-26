import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Session-persistent relay AUTH controller using Mercure SSE.
 *
 * Always active for logged-in users. Subscribes to the user-private Mercure
 * topic `/relay-auth/{pubkey}` via EventSource (cookie-based auth, same as the
 * updates-stream controller). When the relay gateway publishes a NIP-42 AUTH
 * challenge, this controller signs it as the real user and POSTs the signed
 * kind-22242 event back to `/api/relay-auth/{requestId}`. The gateway picks up
 * the signed event from Redis and completes the AUTH handshake with the relay.
 *
 * @stimulusFetch lazy
 */
export default class extends Controller {
  static values = {
    pubkey: String,
    hubUrl: String,
  };

  _processed = new Set();

  connect() {
    if (this._source) {
      return;
    }

    if (!this.pubkeyValue || !this.hubUrlValue) {
      console.debug('[relay-auth] Missing pubkey or hub URL, skipping');
      return;
    }

    if (typeof window.EventSource === 'undefined') {
      console.warn('[relay-auth] EventSource not supported, cannot subscribe to relay AUTH challenges');
      return;
    }

    const topicUrl = `${this.hubUrlValue}?topic=${encodeURIComponent('/relay-auth/' + this.pubkeyValue)}`;

    try {
      // Cookie-based auth — the browser sends the mercureAuthorization HttpOnly
      // cookie automatically. The cookie is scoped to /.well-known/mercure and
      // already includes the /relay-auth/{pubkey} topic in its subscribe claim.
      this._source = new EventSource(topicUrl, { withCredentials: true });
      this._source.onmessage = (e) => this._onMessage(e);
      this._source.onerror = () => {
        // Let the browser reconnect automatically; avoid console spam.
      };
      console.debug('[relay-auth] Subscribed to Mercure relay-auth topic');
    } catch (err) {
      console.warn('[relay-auth] Failed to open EventSource', err);
    }
  }

  disconnect() {
    if (this._source) {
      this._source.close();
      this._source = null;
    }
  }

  _onMessage(event) {
    let data;
    try {
      data = JSON.parse(event.data);
    } catch (e) {
      console.debug('[relay-auth] Ignoring non-JSON SSE message', e);
      return;
    }
    this._handleChallenge(data).catch((e) => {
      console.error('[relay-auth] Unhandled error in _handleChallenge', e);
    });
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

    // Bound the dedup set to avoid unbounded growth over long sessions
    if (this._processed.size > 200) {
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


/**
 * Session-persistent relay AUTH polling controller.
 *
 * Always active for logged-in users. Polls the backend for pending NIP-42
 * AUTH challenges, signs them as the real user, and submits them.
 * This ensures every relay connection authenticated as the user's identity,
 * not as an ephemeral key — even for connections opened mid-session.
 *
 * @stimulusFetch lazy
 */
export default class extends Controller {
  static values = {
    pubkey: String,
    pollUrl: String,
    pollInterval: { type: Number, default: 2000 },
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
    console.debug('[relay-auth] Starting session-persistent relay-auth polling');
    this._poll();
    this._intervalId = window.setInterval(() => this._poll(), this.pollIntervalValue);
  }

  disconnect() {
    if (this._intervalId) {
      window.clearInterval(this._intervalId);
      this._intervalId = null;
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
