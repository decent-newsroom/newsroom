import { Controller } from '@hotwired/stimulus';
import { getPublicKey } from 'nostr-tools';
import { hexToBytes } from 'nostr-tools/utils';
import { BunkerSigner } from "nostr-tools/nip46";
import { setRemoteSignerSession } from './signer_manager.js';

export default class extends Controller {
  static targets = ['qr', 'status', 'uriInput', 'copyButton'];

  connect() {
    this._localSecretKeyHex = null;
    this._localSecretKey = null;
    this._uri = null;
    this._relays = [];
    this._secret = null;
    this._signer = null;
    this._didAuth = false;
    this._init();
  }

  disconnect() {
    try { this._signer?.close?.(); } catch (_) {}
  }

  async _init() {
    try {
      this._setStatus('Requesting pairing QR…');
      const res = await fetch('/nostr-connect/qr');
      if (!res.ok) throw new Error('QR fetch failed');
      const data = await res.json();

      this._localSecretKeyHex = data.privkey;
      this._localSecretKey = hexToBytes(data.privkey);
      this._uri = data.uri;
      this._relays = data.relays || [data.relay].filter(Boolean);
      this._secret = data.secret || null;

      console.log('[amber-connect] URI:', this._uri);
      console.log('[amber-connect] Relays:', this._relays);
      console.log('[amber-connect] Client pubkey:', getPublicKey(this._localSecretKey));

      // Use BunkerSigner.fromURI which handles the full nostrconnect:// flow:
      // 1. Subscribes to the relays in the URI for kind 24133 events
      // 2. Waits for the bunker to send a connect response with the secret
      // 3. Sets up the ongoing subscription for RPC responses
      // All in one atomic, tested operation.
      const CONNECTION_TIMEOUT = 120000; // 2 minutes
      console.log('[amber-connect] Starting BunkerSigner.fromURI (waiting for bunker connect response)...');
      const signerPromise = BunkerSigner.fromURI(
        this._localSecretKey,
        this._uri,
        {},
        CONNECTION_TIMEOUT
      );

      // Show QR/URI immediately — fromURI already subscribed before returning
      if (this.hasQrTarget) {
        this.qrTarget.innerHTML = `<img alt="Amber pairing QR" src="${data.qr}" style="width:260px;height:260px;" />`;
      }
      if (this.hasUriInputTarget) {
        this.uriInputTarget.value = this._uri;
      }
      this._setStatus('Scan with Amber (NIP-46)…');

      // Wait for the bunker to respond
      this._signer = await signerPromise;
      console.log('[amber-connect] ✅ BunkerSigner connected! Bunker pubkey:', this._signer.bp.pubkey);

      this._setStatus('Remote signer connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[amber-connect] init error', e);
      this._setStatus('Init failed: ' + (e.message || 'unknown'));
    }
  }


  async _attemptAuth() {
    if (this._didAuth) return;
    this._didAuth = true;
    try {
      const loginEvent = {
        kind: 27235,
        created_at: Math.floor(Date.now() / 1000),
        tags: [
          ['u', window.location.origin + '/login'],
          ['method', 'POST'],
          ['t', 'bunker']
        ],
        content: ''
      };
      this._setStatus('Signing login request…');
      const signed = await this._signer.signEvent(loginEvent);
      this._setStatus('Submitting login…');
      const resp = await fetch('/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Authorization': 'Nostr ' + btoa(JSON.stringify(signed)) }
      });
      if (resp.ok) {
        setRemoteSignerSession({
          privkey: this._localSecretKeyHex,
          bunkerPointer: this._signer.bp,
          uri: this._uri,
          relays: this._relays,
          secret: this._secret
        });
        this._setStatus('Authenticated. Reloading…');
        setTimeout(() => window.location.reload(), 500);
      } else {
        this._setStatus('Login failed (' + resp.status + ')');
      }
    } catch (e) {
      console.error('[amber-connect] auth error', e);
      this._setStatus('Auth error: ' + (e.message || 'unknown'));
    }
  }

  async copyUri() {
    if (!this._uri) {
      console.warn('[amber-connect] No URI to copy');
      return;
    }

    try {
      await navigator.clipboard.writeText(this._uri);

      // Visual feedback
      if (this.hasCopyButtonTarget) {
        const originalText = this.copyButtonTarget.textContent;
        this.copyButtonTarget.textContent = 'Copied!';
        this.copyButtonTarget.classList.add('btn-success');
        this.copyButtonTarget.classList.remove('btn-outline-secondary');

        setTimeout(() => {
          this.copyButtonTarget.textContent = originalText;
          this.copyButtonTarget.classList.remove('btn-success');
          this.copyButtonTarget.classList.add('btn-outline-secondary');
        }, 2000);
      }

      console.log('[amber-connect] URI copied to clipboard');
    } catch (e) {
      console.error('[amber-connect] Failed to copy URI:', e);

      // Fallback: select the text
      if (this.hasUriInputTarget) {
        this.uriInputTarget.select();
        try {
          document.execCommand('copy');
          console.log('[amber-connect] URI copied using fallback method');
        } catch (fallbackError) {
          console.error('[amber-connect] Fallback copy also failed:', fallbackError);
        }
      }
    }
  }

  _setStatus(msg) {
    if (this.hasStatusTarget) this.statusTarget.textContent = msg;
  }
}
