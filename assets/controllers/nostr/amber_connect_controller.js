import { Controller } from '@hotwired/stimulus';
import { getPublicKey, SimplePool, generateSecretKey } from 'nostr-tools';
import { hexToBytes, bytesToHex } from 'nostr-tools/utils';
import { BunkerSigner, parseBunkerInput } from "nostr-tools/nip46";
import { setRemoteSignerSession } from './signer_manager.js';

export default class extends Controller {
  static targets = ['qr', 'status', 'uriInput', 'copyButton', 'bunkerInput'];

  connect() {
    this._localSecretKeyHex = null;
    this._localSecretKey = null;
    this._uri = null;
    this._relays = [];
    this._secret = null;
    this._signer = null;
    this._didAuth = false;
    this._visibilityHandler = null;
    this._init();
  }

  disconnect() {
    if (this._visibilityHandler) {
      document.removeEventListener('visibilitychange', this._visibilityHandler);
      this._visibilityHandler = null;
    }
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

      // Create a pool with enableReconnect so WebSocket connections survive mobile
      // tab suspension. When the user switches to Amber to approve, the browser
      // suspends WebSockets. enableReconnect ensures they auto-reconnect when
      // the user switches back, and nostr-tools re-sends REQ with a since filter
      // so the signed response event is picked up.
      const pool = new SimplePool({ enableReconnect: true });
      const CONNECTION_TIMEOUT = 120000; // 2 minutes
      console.log('[amber-connect] Starting BunkerSigner.fromURI (waiting for bunker connect response)...');
      const signerPromise = BunkerSigner.fromURI(
        this._localSecretKey,
        this._uri,
        { pool },
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

  /**
   * Reconnect using a bunker:// URI from an existing bunker session.
   * This is the bunker-initiated flow (Method 1):
   *   parseBunkerInput() → fromBunker() → connect() → signEvent() → login
   * Much more reliable on mobile since the bunker already has an active session
   * and auto-approves requests — no app-switching needed during the RPC phase.
   */
  async connectBunker() {
    const input = this.hasBunkerInputTarget ? this.bunkerInputTarget.value.trim() : '';
    if (!input) {
      this._setStatus('Please paste a bunker:// connection string.');
      return;
    }

    try {
      this._setStatus('Parsing bunker connection…');
      const bunkerPointer = await parseBunkerInput(input);
      if (!bunkerPointer) {
        this._setStatus('Invalid bunker string. Expected bunker://… or a NIP-05 address.');
        return;
      }
      console.log('[amber-connect] Parsed bunker pointer:', bunkerPointer);

      // Generate a fresh local keypair for this session
      const localSecretKey = generateSecretKey();
      this._localSecretKey = localSecretKey;
      this._localSecretKeyHex = bytesToHex(localSecretKey);
      this._relays = bunkerPointer.relays;
      this._secret = bunkerPointer.secret;

      this._setStatus('Connecting to bunker…');
      console.log('[amber-connect] Creating BunkerSigner.fromBunker with relays:', bunkerPointer.relays);

      // fromBunker returns immediately, sets up subscription
      this._signer = BunkerSigner.fromBunker(localSecretKey, bunkerPointer);

      // connect() sends the "connect" RPC and waits for "ack"
      console.log('[amber-connect] Calling connect()…');
      await this._signer.connect();
      console.log('[amber-connect] ✅ Connected to bunker! Pubkey:', this._signer.bp.pubkey);

      this._setStatus('Connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[amber-connect] connectBunker error:', e);
      this._setStatus('Bunker connection failed: ' + (e.message || 'unknown'));
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
