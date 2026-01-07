import { Controller } from '@hotwired/stimulus';
import { getPublicKey, SimplePool } from 'nostr-tools';
import { BunkerSigner } from "nostr-tools/nip46";
import { setRemoteSignerSession } from '../nostr/signer_manager.js';

export default class extends Controller {
  static targets = ['dialog', 'qr', 'status'];

  connect() {
    console.log('[signer-modal] controller connected');
    this._localSecretKey = null;
    this._uri = null;
    this._relays = [];
    this._secret = null;
    this._signer = null;
    this._pool = null;
    this._didAuth = false;
  }

  disconnect() {
    try { this._signer?.close?.(); } catch (_) {}
    try { this._pool?.close?.([]); } catch (_) {}
  }

  async openDialog() {
    console.log('[signer-modal] openDialog called', this.hasDialogTarget);
    if (this.hasDialogTarget) {
      this.dialogTarget.style.display = 'block';
      await this._init();
    } else {
      console.error('[signer-modal] dialog target not found');
    }
  }

  closeDialog() {
    if (this.hasDialogTarget) {
      this.dialogTarget.style.display = 'none';
      // Clean up resources
      try { this._signer?.close?.(); } catch (_) {}
      try { this._pool?.close?.([]); } catch (_) {}
      this._didAuth = false;
    }
  }

  async _init() {
    try {
      this._setStatus('Requesting pairing QR…');
      const res = await fetch('/nostr-connect/qr');
      if (!res.ok) throw new Error('QR fetch failed');
      const data = await res.json();

      this._localSecretKey = data.privkey;
      this._uri = data.uri;
      this._relays = data.relays || [data.relay].filter(Boolean);
      this._secret = data.secret || null;

      if (this.hasQrTarget) {
        this.qrTarget.innerHTML = `<img alt="Amber pairing QR" src="${data.qr}" style="width:260px;height:260px;" />`;
      }

      this._checkClientPubkeyIntegrity();

      this._setStatus('Scan with Amber (NIP-46)…');
      await this._createSigner();
      this._setStatus('Remote signer connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[signer-modal] init error', e);
      this._setStatus('Init failed: ' + (e.message || 'unknown'));
    }
  }

  _checkClientPubkeyIntegrity() {
    try {
      if (!this._localSecretKey || !this._uri) return;
      const derived = getPublicKey(this._localSecretKey);
      const m = this._uri.match(/^nostrconnect:\/\/([0-9a-fA-F]{64})/);
      if (!m) {
        console.warn('[signer-modal] URI missing/invalid pubkey segment');
        return;
      }
      const uriPk = m[1].toLowerCase();
      if (uriPk !== derived.toLowerCase()) {
        console.warn('[signer-modal] Pubkey mismatch: derived != URI', { derived, uriPk });
      }
    } catch (e) {
      console.warn('[signer-modal] integrity check failed', e);
    }
  }

  async _createSigner() {
    this._pool = new SimplePool();
    this._setStatus('Waiting for remote signer…');
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
        setRemoteSignerSession({
          privkey: this._localSecretKey,
          bunkerPointer: this._signer.bp,
          uri: this._uri,
          relays: this._relays,
          secret: this._secret
        });
        this._setStatus('Authenticated. Reloading…');

        // Save editor state before reload (if in editor)
        if (typeof window.saveEditorStateBeforeLogin === 'function') {
          window.saveEditorStateBeforeLogin();
        }

        setTimeout(() => window.location.reload(), 500);
      } else {
        this._setStatus('Login failed (' + resp.status + ')');
      }
    } catch (e) {
      console.error('[signer-modal] auth error', e);
      this._setStatus('Auth error: ' + (e.message || 'unknown'));
    }
  }

  _setStatus(msg) {
    if (this.hasStatusTarget) this.statusTarget.textContent = msg;
  }
}

