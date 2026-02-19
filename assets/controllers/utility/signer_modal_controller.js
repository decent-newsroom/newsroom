import { Controller } from '@hotwired/stimulus';
import { getPublicKey, SimplePool } from 'nostr-tools';
import { hexToBytes } from 'nostr-tools/utils';
import { BunkerSigner } from "nostr-tools/nip46";
import * as nip44 from 'nostr-tools/nip44';
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

      // Store both formats: hex string for getPublicKey, Uint8Array for BunkerSigner
      this._localSecretKeyHex = data.privkey;
      this._localSecretKey = hexToBytes(data.privkey);
      this._uri = data.uri;
      this._relays = data.relays || [data.relay].filter(Boolean);
      this._secret = data.secret || null;

      this._checkClientPubkeyIntegrity();

      // CRITICAL: Start creating signer and subscribing BEFORE showing QR/URI
      this._setStatus('Waiting for remote signer…');
      console.log('[signer-modal] Starting signer creation BEFORE displaying QR...');
      const signerPromise = this._createSigner();

      // Small delay to ensure subscription is established
      await new Promise(resolve => setTimeout(resolve, 100));

      // Now show QR/URI after subscription is active
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
      console.error('[signer-modal] init error', e);
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
        console.warn('[signer-modal] Pubkey mismatch: derived != URI');
      }
    } catch (e) {
      console.warn('[signer-modal] integrity check failed', e);
    }
  }

  async _createSigner() {
    if (!this._pool) {
      this._pool = new SimplePool();
    }

    const CONNECTION_TIMEOUT = 90000; // 90 seconds total

    try {
      const clientPubkey = getPublicKey(this._localSecretKey);

      // WORKAROUND: Bunkers often ignore the relay list in the URI and use their own default relays
      // So we subscribe to BOTH the URI relays AND common bunker relays
      const subscriptionRelays = [
        ...this._relays,  // Relays from our nostrconnect:// URI
        'wss://relay.nsec.app',  // Common bunker relay
        'wss://relay.primal.net',  // Common bunker relay
        'wss://relay.damus.io',  // Common bunker relay
        'wss://nos.lol',  // Common bunker relay
        'wss://relay.snort.social',  // Common bunker relay
      ];

      // Remove duplicates
      const uniqueRelays = [...new Set(subscriptionRelays)];

      console.log('[signer-modal] 🔍 Subscribing for connect event immediately...');
      console.log('[signer-modal] Client pubkey:', clientPubkey);
      console.log('[signer-modal] URI relays:', this._relays);
      console.log('[signer-modal] Subscribing to (including bunker defaults):', uniqueRelays);
      console.log('[signer-modal] Expected secret:', this._secret);

      // Subscribe to expanded relay list to catch event wherever bunker sends it
      const bunkerPubkey = await this._waitForConnectEvent(clientPubkey, CONNECTION_TIMEOUT, uniqueRelays);

      console.log('[signer-modal] 📍 Got bunker pubkey:', bunkerPubkey);

      // Create BunkerPointer
      const bunkerPointer = {
        pubkey: bunkerPubkey,
        relays: this._relays,
        secret: this._secret
      };

      // Create signer with fromBunker
      this._signer = BunkerSigner.fromBunker(
        this._localSecretKey,
        bunkerPointer,
        { pool: this._pool }
      );

      console.log('[signer-modal] ✅ BunkerSigner created!');

      // Call connect() to establish relay subscription for receiving sign responses
      console.log('[signer-modal] Calling connect() to establish relay subscription...');
      await this._signer.connect();
      console.log('[signer-modal] ✅ BunkerSigner connected to relay!');
    } catch (error) {
      console.error('[signer-modal] ❌ Connection failed:', error.message);
      throw new Error('Connection to remote signer failed. Check: (1) Bunker has the URI, (2) Relay connectivity');
    }
  }

  async _waitForConnectEvent(clientPubkey, timeout, relays) {
    return new Promise((resolve, reject) => {
      const timeoutId = setTimeout(() => {
        sub.close();
        reject(new Error(`Connection timed out after ${timeout/1000} seconds`));
      }, timeout);

      console.log('[signer-modal] 🔍 Subscribing for connect event...');
      console.log('[signer-modal] Client pubkey:', clientPubkey);
      console.log('[signer-modal] Subscribing to relays:', relays);
      console.log('[signer-modal] Expected secret:', this._secret);

      const sub = this._pool.subscribe(
        relays,
        { kinds: [24133], "#p": [clientPubkey] },
        {
          onevent: async (event) => {
            console.log('[signer-modal] 📨 Received kind 24133 event from:', event.pubkey);
            console.log('[signer-modal] Event content (encrypted):', event.content.substring(0, 50) + '...');

            try {
              // Decrypt the NIP-44 encrypted content
              const conversationKey = nip44.v2.utils.getConversationKey(
                this._localSecretKey,
                event.pubkey
              );
              const decrypted = nip44.v2.decrypt(event.content, conversationKey);
              console.log('[signer-modal] 🔓 Decrypted content:', decrypted);

              const parsed = JSON.parse(decrypted);
              console.log('[signer-modal] 📋 Parsed message:', parsed);

              // Check if result matches our secret
              if (parsed.result === this._secret) {
                console.log('[signer-modal] ✅ Connection established - secret matches!');
                clearTimeout(timeoutId);
                sub.close();
                resolve(event.pubkey);
              } else {
                console.warn('[signer-modal] ⚠️  Secret mismatch. Expected:', this._secret, 'Got:', parsed.result);
              }
            } catch (e) {
              // Decryption failed, keep waiting for correct event
              console.warn('[signer-modal] ❌ Event decryption/parsing failed:', e.message);
              console.warn('[signer-modal] Event:', event);
            }
          },
          oneose: () => {
            console.log('[signer-modal] 📡 EOSE received. Waiting for remote signer response...');
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
