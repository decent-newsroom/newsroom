import { Controller } from '@hotwired/stimulus';
import { getPublicKey } from 'nostr-tools';
import { hexToBytes } from 'nostr-tools/utils';
import { BunkerSigner } from "nostr-tools/nip46";
import { setRemoteSignerSession } from '../nostr/signer_manager.js';

export default class extends Controller {
  static targets = ['dialog', 'qr', 'status', 'uriInput', 'copyButton'];

  connect() {
    this._localSecretKeyHex = null;
    this._localSecretKey = null;
    this._uri = null;
    this._relays = [];
    this._secret = null;
    this._signer = null;
    this._didAuth = false;
  }

  disconnect() {
    try { this._signer?.close?.(); } catch (_) {}
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
      try { this._signer?.close?.(); } catch (_) {}
      this._didAuth = false;
    }
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

      console.log('[signer-modal] URI:', this._uri);
      console.log('[signer-modal] Relays:', this._relays);
      console.log('[signer-modal] Client pubkey:', getPublicKey(this._localSecretKey));

      // Use BunkerSigner.fromURI which handles the full nostrconnect:// flow:
      // 1. Subscribes to the relays in the URI for kind 24133 events
      // 2. Waits for the bunker to send a connect response with the secret
      // 3. Sets up the ongoing subscription for RPC responses
      // All in one atomic, tested operation using its own internal pool.
      const CONNECTION_TIMEOUT = 120000; // 2 minutes
      console.log('[signer-modal] Starting BunkerSigner.fromURI (waiting for bunker connect response)...');
      const signerPromise = BunkerSigner.fromURI(
        this._localSecretKey,
        this._uri,
        {},
        CONNECTION_TIMEOUT
      );

      // Show QR/URI — fromURI already subscribed internally before we get here
      if (this.hasQrTarget) {
        this.qrTarget.innerHTML = `<img alt="Bunker pairing QR" src="${data.qr}" style="width:260px;height:260px;" />`;
      }
      if (this.hasUriInputTarget) {
        this.uriInputTarget.value = this._uri;
      }
      this._setStatus('Paste into your bunker (NIP-46)…');

      // Wait for the bunker to respond
      this._signer = await signerPromise;
      console.log('[signer-modal] ✅ BunkerSigner connected! Bunker pubkey:', this._signer.bp.pubkey);

      this._setStatus('Remote signer connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[signer-modal] init error', e);
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
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'Authorization': 'Nostr ' + btoa(JSON.stringify(signed))
        },
        body: '{}'
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

  async copyUri() {
    if (!this._uri) {
      console.warn('[signer-modal] No URI to copy');
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

      console.log('[signer-modal] URI copied to clipboard');
    } catch (e) {
      console.error('[signer-modal] Failed to copy URI:', e);

      // Fallback: select the text
      if (this.hasUriInputTarget) {
        this.uriInputTarget.select();
        try {
          document.execCommand('copy');
          console.log('[signer-modal] URI copied using fallback method');
        } catch (fallbackError) {
          console.error('[signer-modal] Fallback copy also failed:', fallbackError);
        }
      }
    }
  }

  _setStatus(msg) {
    if (this.hasStatusTarget) this.statusTarget.textContent = msg;
  }
}
