import { Controller } from '@hotwired/stimulus';
import { getPublicKey,  SimplePool  } from 'nostr-tools';
import { BunkerSigner } from "nostr-tools/nip46";
import { setRemoteSignerSession, clearRemoteSignerSession } from './signer_manager.js';

export default class extends Controller {
  static targets = ['qr', 'status'];

  connect() {
    this._localSecretKey = null; // hex (32 bytes) from server
    this._uri = null;            // nostrconnect:// URI from server (NOT re-generated client side)
    this._relays = [];
    this._secret = null;
    this._signer = null;
    this._pool = null;
    this._didAuth = false;
    this._init();
  }

  disconnect() {
    try { this._signer?.close?.(); } catch (_) {}
    try { this._pool?.close?.([]); } catch (_) {}
    clearRemoteSignerSession();
  }

  async _init() {
    try {
      this._setStatus('Requesting pairing QR…');
      const res = await fetch('/nostr-connect/qr');
      if (!res.ok) throw new Error('QR fetch failed');
      const data = await res.json();

      this._localSecretKey = data.privkey; // hex secret key (client keypair)
      this._uri = data.uri;                // full nostrconnect URI (already includes relays, secret, name)
      this._relays = data.relays || [data.relay].filter(Boolean);
      this._secret = data.secret || null;

      if (this.hasQrTarget) {
        this.qrTarget.innerHTML = `<img alt="Amber pairing QR" src="${data.qr}" style="width:260px;height:260px;" />`;
      }

      // Integrity check: derive pubkey from provided privkey and compare to URI authority
      this._checkClientPubkeyIntegrity();

      this._setStatus('Scan with Amber (NIP-46)…');
      await this._createSigner();
      this._setStatus('Remote signer connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[amber-connect] init error', e);
      this._setStatus('Init failed: ' + (e.message || 'unknown'));
    }
  }

  _checkClientPubkeyIntegrity() {
    try {
      if (!this._localSecretKey || !this._uri) return;
      const derived = getPublicKey(this._localSecretKey);
      const m = this._uri.match(/^nostrconnect:\/\/([0-9a-fA-F]{64})/);
      if (!m) {
        console.warn('[amber-connect] URI missing/invalid pubkey segment');
        return;
      }
      const uriPk = m[1].toLowerCase();
      if (uriPk !== derived.toLowerCase()) {
        console.warn('[amber-connect] Pubkey mismatch: derived != URI', { derived, uriPk });
      }
    } catch (e) {
      console.warn('[amber-connect] integrity check failed', e);
    }
  }

  async _createSigner() {
    this._pool = new SimplePool();
    this._setStatus('Waiting for remote signer…');
    // INITIAL CONNECTION: fromURI() waits for Amber to accept connection (NIP-46 connect handshake)
    // After this succeeds, the session (privkey, uri, relays, secret) is persisted to localStorage
    // Subsequent calls to BunkerSigner.fromURI() with same credentials should work without waiting for approval
    this._signer = await BunkerSigner.fromURI(this._localSecretKey, this._uri, { pool: this._pool });
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
        // Persist remote signer session for reuse after reload
        // Note: Reconnection with Amber may require user approval each time
        setRemoteSignerSession({
          privkey: this._localSecretKey,
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

  _setStatus(msg) {
    if (this.hasStatusTarget) this.statusTarget.textContent = msg;
  }
}
