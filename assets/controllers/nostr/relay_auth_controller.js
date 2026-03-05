import { Controller } from '@hotwired/stimulus';
import { getSigner } from './signer_manager.js';

/**
 * Listens for NIP-42 AUTH challenges pushed by the relay gateway via Mercure SSE.
 *
 * When the relay gateway opens an authenticated connection to a relay on
 * behalf of the logged-in user, the relay sends an AUTH challenge. The gateway
 * publishes it to Mercure topic `/relay-auth/{pubkey}`. This controller:
 *
 *   1. Subscribes to the Mercure SSE topic
 *   2. Receives the challenge (requestId, relay URL, challenge string)
 *   3. Builds a kind 22242 AUTH event skeleton
 *   4. Signs it with getSigner() (browser extension or NIP-46 bunker)
 *   5. POSTs the signed event to /api/relay-auth/{requestId}
 *
 * The gateway polls Redis for the signed event and completes the handshake.
 *
 * Mount on base layout for logged-in users:
 *   <div data-controller="nostr--relay-auth"
 *        data-nostr--relay-auth-pubkey-value="{{ app.user.npub }}"
 *        data-nostr--relay-auth-mercure-hub-value="{{ mercure_public_hub_url }}">
 *   </div>
 *
 * @stimulusFetch lazy
 */
export default class extends Controller {
  static values = {
    pubkey: String,     // user's hex pubkey (for Mercure topic)
    mercureHub: String, // Mercure hub URL
  };

  /** @type {EventSource|null} */
  _eventSource = null;

  /** @type {Set<string>} Track processed request IDs to avoid double-signing */
  _processed = new Set();

  connect() {
    if (!this.pubkeyValue || !this.mercureHubValue) {
      console.debug('[relay-auth] Missing pubkey or mercureHub value, skipping');
      return;
    }

    this._subscribe();
  }

  disconnect() {
    this._close();
  }

  _subscribe() {
    const topic = `/relay-auth/${this.pubkeyValue}`;
    const url = new URL(this.mercureHubValue);
    url.searchParams.append('topic', topic);

    console.debug('[relay-auth] Subscribing to Mercure topic:', topic);

    this._eventSource = new EventSource(url, { withCredentials: true });

    this._eventSource.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        this._handleChallenge(data);
      } catch (e) {
        console.error('[relay-auth] Failed to parse Mercure message:', e);
      }
    };

    this._eventSource.onerror = (e) => {
      console.warn('[relay-auth] Mercure SSE error, will auto-reconnect:', e);
    };
  }

  _close() {
    if (this._eventSource) {
      this._eventSource.close();
      this._eventSource = null;
    }
  }

  /**
   * Handle an AUTH challenge from the gateway.
   * @param {{ requestId: string, relay: string, challenge: string }} data
   */
  async _handleChallenge(data) {
    const { requestId, relay, challenge } = data;

    if (!requestId || !relay || !challenge) {
      console.warn('[relay-auth] Invalid challenge data:', data);
      return;
    }

    // Deduplicate (Mercure can redeliver)
    if (this._processed.has(requestId)) {
      console.debug('[relay-auth] Already processed request:', requestId);
      return;
    }
    this._processed.add(requestId);

    // Evict old entries to prevent memory leak
    if (this._processed.size > 100) {
      const first = this._processed.values().next().value;
      this._processed.delete(first);
    }

    console.info('[relay-auth] AUTH challenge received', { requestId, relay });

    try {
      // Build the kind 22242 AUTH event skeleton
      const authEvent = {
        kind: 22242,
        created_at: Math.floor(Date.now() / 1000),
        tags: [
          ['relay', relay],
          ['challenge', challenge],
        ],
        content: '',
      };

      // Sign with the user's signer
      const signer = await getSigner();
      const signed = await signer.signEvent(authEvent);

      console.info('[relay-auth] Event signed, submitting to API', { requestId });

      // POST signed event to the API
      const response = await fetch(`/api/relay-auth/${requestId}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({ signedEvent: signed }),
      });

      if (response.ok) {
        console.info('[relay-auth] AUTH signed and submitted successfully', { requestId, relay });
      } else {
        const errBody = await response.json().catch(() => ({}));
        console.warn('[relay-auth] API rejected signed event', {
          requestId,
          status: response.status,
          error: errBody.error,
        });
      }
    } catch (e) {
      console.error('[relay-auth] Failed to sign/submit AUTH', { requestId, relay, error: e.message });
      // Don't throw — this is best-effort. The gateway will time out gracefully.
    }
  }
}

