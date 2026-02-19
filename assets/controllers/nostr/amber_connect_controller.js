import { Controller } from '@hotwired/stimulus';
import { getPublicKey, SimplePool } from 'nostr-tools';
import { hexToBytes } from 'nostr-tools/utils';
import { BunkerSigner } from "nostr-tools/nip46";
import * as nip44 from 'nostr-tools/nip44';
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
    this._pool = null;
    this._didAuth = false;
    this._init();
  }

  disconnect() {
    try { this._signer?.close?.(); } catch (_) {}
    try { this._pool?.close?.([]); } catch (_) {}
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

      this._checkClientPubkeyIntegrity();

      // Start listening for connection BEFORE showing QR/URI
      this._setStatus('Waiting for remote signer…');
      const signerPromise = this._createSigner();

      // Now show QR/URI after we're listening
      if (this.hasQrTarget) {
        this.qrTarget.innerHTML = `<img alt="Amber pairing QR" src="${data.qr}" style="width:260px;height:260px;" />`;
      }

      if (this.hasUriInputTarget) {
        this.uriInputTarget.value = this._uri;
      }

      this._setStatus('Scan with Amber (NIP-46)…');

      // Wait for the connection to complete
      await signerPromise;
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
      if (!m) return;
      const uriPk = m[1].toLowerCase();
      if (uriPk !== derived.toLowerCase()) {
        console.warn('[amber-connect] Pubkey mismatch: derived != URI');
      }
    } catch (e) {
      console.warn('[amber-connect] integrity check failed', e);
    }
  }

  async _createSigner() {
    if (!this._pool) {
      this._pool = new SimplePool();
    }

    const CONNECTION_TIMEOUT = 60000; // 60 seconds

    try {
      this._setStatus('Waiting for remote signer…');
      const clientPubkey = getPublicKey(this._localSecretKey);

      // Wait for the connect event, then use fromBunker()
      const bunkerPubkey = await this._waitForConnectEvent(clientPubkey, CONNECTION_TIMEOUT);

      // Create BunkerPointer for fromBunker()
      const bunkerPointer = {
        pubkey: bunkerPubkey,
        relays: this._relays,
        secret: this._secret
      };

      // Use fromBunker() - we already handled the connect handshake
      this._signer = BunkerSigner.fromBunker(
        this._localSecretKey,
        bunkerPointer,
        { pool: this._pool }
      );

      console.log('[amber-connect] ✅ BunkerSigner created successfully');
    } catch (error) {
      console.error('[amber-connect] ❌ Connection failed:', error.message);
      throw error;
    }
  }

  async _waitForConnectEvent(clientPubkey, timeout) {
    return new Promise((resolve, reject) => {
      const timeoutId = setTimeout(() => {
        sub.close();
        reject(new Error(`Connection timed out after ${timeout/1000} seconds`));
      }, timeout);

      // Start listening from a bit before now to catch events that might arrive during setup
      const sinceTimestamp = Math.floor(Date.now() / 1000) - 10; // 10 seconds ago

      const sub = this._pool.subscribe(
        this._relays,
        { kinds: [24133], "#p": [clientPubkey], since: sinceTimestamp },
        {
          onevent: async (event) => {
            try {
              // Decrypt the NIP-44 encrypted content
              const conversationKey = nip44.v2.utils.getConversationKey(
                this._localSecretKey,
                event.pubkey
              );
              const decrypted = nip44.v2.decrypt(event.content, conversationKey);
              const parsed = JSON.parse(decrypted);

              // Check if result matches our secret
              if (parsed.result === this._secret) {
                console.log('[amber-connect] ✅ Connection established');
                clearTimeout(timeoutId);
                sub.close();
                resolve(event.pubkey);
              }
            } catch (e) {
              console.warn('[amber-connect] Event decryption failed, waiting...', e.message);
            }
          },
          oneose: () => {
            console.log('[amber-connect] Waiting for remote signer response...');
          }
        }
      );
    });
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
