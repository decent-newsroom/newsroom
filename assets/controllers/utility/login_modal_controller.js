import { Controller } from '@hotwired/stimulus';
// nostr-tools imports are lazy-loaded inside methods to avoid
// pulling the full crypto/WebSocket bundle on pages that never open the modal.

export default class extends Controller {
  static targets = [
    'dialog',
    'nostrError',
    'qr', 'status', 'uriInput', 'copyButton',
    'amberDeepLink', 'amberDeepLinkBtn',
    'bunkerInput', 'remoteStatus',
  ];

  connect() {
    this._localSecretKeyHex = null;
    this._localSecretKey = null;
    this._uri = null;
    this._relays = [];
    this._secret = null;
    this._signer = null;
    this._didAuth = false;
    this._nip46Initialized = false;
    this._visibilityHandler = null;
    this._currentOption = null;
  }

  disconnect() {
    if (this._visibilityHandler) {
      document.removeEventListener('visibilitychange', this._visibilityHandler);
      this._visibilityHandler = null;
    }
    try { this._signer?.close?.(); } catch (_) {}
  }

  openDialog() {
    if (this.hasDialogTarget) {
      this.dialogTarget.style.display = 'block';
      this._showPanel('picker');
    }
  }

  closeDialog() {
    if (this.hasDialogTarget) {
      this.dialogTarget.style.display = 'none';
      if (this._visibilityHandler) {
        document.removeEventListener('visibilitychange', this._visibilityHandler);
        this._visibilityHandler = null;
      }
      try { this._signer?.close?.(); } catch (_) {}
      this._didAuth = false;
    }
  }

  /**
   * Called by option buttons via data-action.
   * Reads `data-utility--login-modal-option-param` for the chosen option.
   */
  showOption(event) {
    const option = event.params.option; // 'extension' | 'amber' | 'primal' | 'remote'
    this._currentOption = option;

    if (option === 'extension') {
      this._showPanel('extension');
    } else if (option === 'amber') {
      this._showPanel('nip46');
      this._updateNip46Panel('amber');
      this._initNip46();
    } else if (option === 'primal') {
      this._showPanel('nip46');
      this._updateNip46Panel('primal');
      this._initNip46();
    } else if (option === 'remote') {
      this._showPanel('remote');
      this._initNip46();
    }
  }

  backToPicker() {
    this._showPanel('picker');
  }

  // ── Panel helpers ────────────────────────────────────────────────────────

  _showPanel(name) {
    this.element.querySelectorAll('[data-panel]').forEach(el => {
      el.style.display = el.dataset.panel === name ? '' : 'none';
    });
  }

  _updateNip46Panel(option) {
    const titleEl = this.element.querySelector('[data-nip46-title]');
    const hintEl  = this.element.querySelector('[data-nip46-hint]');
    if (option === 'amber') {
      if (titleEl) titleEl.textContent = 'Amber';
      if (hintEl)  hintEl.textContent  = 'Scan the QR code with the Amber app on your Android device, or tap the button to open it directly.';
      if (this.hasAmberDeepLinkTarget) this.amberDeepLinkTarget.style.display = '';
    } else {
      if (titleEl) titleEl.textContent = 'Primal';
      if (hintEl)  hintEl.textContent  = 'Open Primal on your device and scan the QR code, or copy the connection string.';
      if (this.hasAmberDeepLinkTarget) this.amberDeepLinkTarget.style.display = 'none';
    }
  }

  // ── Extension (NIP-07) ───────────────────────────────────────────────────

  async loginExtension() {
    if (!window.nostr) {
      this._setExtensionError('No extension found. Please install a NIP-07 browser extension (Alby, nos2x, Nostore, Flamingo…).');
      return;
    }
    this._setExtensionError(null);

    let pubkey;
    try {
      pubkey = await window.nostr.getPublicKey();
    } catch (e) {
      this._setExtensionError('Failed to get public key: ' + e.message);
      return;
    }

    const ev = {
      created_at: Math.floor(Date.now() / 1000),
      kind: 27235,
      tags: [
        ['u', window.location.origin + '/login'],
        ['method', 'POST'],
        ['t', 'extension'],
      ],
      content: '',
      pubkey,
    };

    let signed;
    try {
      signed = await window.nostr.signEvent(ev);
    } catch (e) {
      this._setExtensionError('Signing rejected: ' + e.message);
      return;
    }

    const resp = await fetch('/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Nostr ' + btoa(JSON.stringify(signed)),
      },
      body: '{}',
    });

    if (resp.ok) {
      if (typeof window.saveEditorStateBeforeLogin === 'function') {
        window.saveEditorStateBeforeLogin();
      }
      window.location.reload();
    } else {
      this._setExtensionError('Login failed (' + resp.status + '). Please try again.');
    }
  }

  _setExtensionError(msg) {
    if (!this.hasNostrErrorTarget) return;
    if (msg) {
      this.nostrErrorTarget.textContent = msg;
      this.nostrErrorTarget.style.display = '';
    } else {
      this.nostrErrorTarget.textContent = '';
      this.nostrErrorTarget.style.display = 'none';
    }
  }

  // ── NIP-46 QR flow (Amber & Primal) ─────────────────────────────────────

  async _initNip46() {
      if (this._nip46Initialized) {
      // Already initialized — re-populate visible panel's elements and refresh deep-link if needed
      this.qrTargets.forEach(el => {
        if (!el.innerHTML) el.innerHTML = `<img alt="Bunker pairing QR" src="" style="width:220px;height:220px;" />`;
      });
      this.uriInputTargets.forEach(el => { el.value = this._uri || ''; });
      if (this._currentOption === 'amber' && this._uri && this.hasAmberDeepLinkBtnTarget) {
        this.amberDeepLinkBtnTarget.href = this._uri;
      }
      return;
    }
    this._nip46Initialized = true;

    const { SimplePool, finalizeEvent } = await import('nostr-tools');
    const { hexToBytes } = await import('nostr-tools/utils');
    const { BunkerSigner } = await import('nostr-tools/nip46');

    try {
      this._setStatus('Requesting pairing QR…');
      const res = await fetch('/nostr-connect/qr');
      if (!res.ok) throw new Error('QR fetch failed');
      const data = await res.json();

      this._localSecretKeyHex = data.privkey;
      this._localSecretKey    = hexToBytes(data.privkey);
      this._uri               = data.uri;
      this._relays            = data.relays || [data.relay].filter(Boolean);
      this._secret            = data.secret || null;

      console.log('[login-modal] NIP-46 URI:', this._uri);

      const pool = new SimplePool({
        enableReconnect: true,
        automaticallyAuth: () => (evt) => finalizeEvent(evt, this._localSecretKey),
      });

      const signerPromise = BunkerSigner.fromURI(
        this._localSecretKey,
        this._uri,
        { pool },
        120000
      );

      // Populate all QR containers and URI inputs (nip46 panel + remote panel)
      this.qrTargets.forEach(el => {
        el.innerHTML = `<img alt="Bunker pairing QR" src="${data.qr}" style="width:220px;height:220px;" />`;
      });
      this.uriInputTargets.forEach(el => { el.value = this._uri; });
      if (this.hasAmberDeepLinkBtnTarget) {
        // Use nostrconnect:// directly — Amber registers this scheme for NIP-46
        // connection initiation. Using nostrsigner: routes to Amber's signing
        // requests screen instead, which shows "nothing to approve yet".
        this.amberDeepLinkBtnTarget.href = this._uri;
      }

      this._setStatus('Awaiting signal…');
      this._signer = await signerPromise;
      console.log('[login-modal] BunkerSigner connected, pubkey:', this._signer.bp.pubkey);

      this._setStatus('Connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[login-modal] NIP-46 init error', e);
      this._setStatus('Connection failed: ' + (e.message || 'unknown'));
    }
  }

  // ── Bunker paste (Remote Signer) ─────────────────────────────────────────

  async connectBunker() {
    const input = this.hasBunkerInputTarget ? this.bunkerInputTarget.value.trim() : '';
    if (!input) {
      this._setRemoteStatus('Please paste a bunker:// connection string.');
      return;
    }

    const { SimplePool, generateSecretKey, finalizeEvent } = await import('nostr-tools');
    const { bytesToHex } = await import('nostr-tools/utils');
    const { BunkerSigner, parseBunkerInput } = await import('nostr-tools/nip46');

    try {
      this._setRemoteStatus('Parsing connection string…');
      const bunkerPointer = await parseBunkerInput(input);
      if (!bunkerPointer) {
        this._setRemoteStatus('Invalid string. Expected bunker://… or a NIP-05 address.');
        return;
      }

      const localSecretKey = generateSecretKey();
      this._localSecretKey    = localSecretKey;
      this._localSecretKeyHex = bytesToHex(localSecretKey);
      this._relays            = bunkerPointer.relays;
      this._secret            = bunkerPointer.secret;

      const pool = new SimplePool({
        enableReconnect: true,
        automaticallyAuth: () => (evt) => finalizeEvent(evt, localSecretKey),
      });

      this._setRemoteStatus('Connecting to relays…');
      await Promise.allSettled(
        bunkerPointer.relays.map(r => pool.ensureRelay(r, { connectionTimeout: 15000 }))
      );

      this._signer = BunkerSigner.fromBunker(localSecretKey, bunkerPointer, { pool });

      this._setRemoteStatus('Connecting to bunker…');
      await this._signer.connect();
      console.log('[login-modal] Bunker connected, pubkey:', this._signer.bp.pubkey);

      this._setRemoteStatus('Connected. Authenticating…');
      await this._attemptAuth();
    } catch (e) {
      console.error('[login-modal] connectBunker error:', e);
      this._setRemoteStatus('Connection failed: ' + (e.message || 'unknown'));
    }
  }

  // ── Shared auth ──────────────────────────────────────────────────────────

  async _attemptAuth() {
    if (this._didAuth) return;
    this._didAuth = true;
    const { setRemoteSignerSession } = await import('../nostr/signer_manager.js');
    try {
      const loginEvent = {
        kind: 27235,
        created_at: Math.floor(Date.now() / 1000),
        tags: [
          ['u', window.location.origin + '/login'],
          ['method', 'POST'],
          ['t', 'bunker'],
        ],
        content: '',
      };

      const statusMsg = msg => { this._setStatus(msg); this._setRemoteStatus(msg); };

      statusMsg('Signing login request…');
      const signed = await this._signer.signEvent(loginEvent);
      statusMsg('Submitting login…');

      const resp = await fetch('/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'Authorization': 'Nostr ' + btoa(JSON.stringify(signed)),
        },
        body: '{}',
      });

      if (resp.ok) {
        setRemoteSignerSession({
          privkey:       this._localSecretKeyHex,
          bunkerPointer: this._signer.bp,
          uri:           this._uri,
          relays:        this._relays,
          secret:        this._secret,
        });
        statusMsg('Authenticated. Reloading…');
        await this._registerServerSession().catch(() => {/* non-fatal */});
        if (typeof window.saveEditorStateBeforeLogin === 'function') {
          window.saveEditorStateBeforeLogin();
        }
        setTimeout(() => window.location.reload(), 500);
      } else {
        statusMsg('Login failed (' + resp.status + ')');
      }
    } catch (e) {
      console.error('[login-modal] auth error', e);
      const msg = 'Auth error: ' + (e.message || 'unknown');
      this._setStatus(msg);
      this._setRemoteStatus(msg);
    }
  }

  async _registerServerSession() {
    const bp = this._signer.bp;
    if (!bp?.pubkey || !bp?.relays || !this._localSecretKeyHex) return;
    const resp = await fetch('/api/nostr-connect/session', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        clientPrivkeyHex: this._localSecretKeyHex,
        bunkerPubkeyHex:  bp.pubkey,
        bunkerRelays:     bp.relays,
      }),
    });
    if (resp.ok) localStorage.removeItem('nip46_server_sync_pending');
  }

  async copyUri() {
    if (!this._uri) return;
    try {
      await navigator.clipboard.writeText(this._uri);
      this.copyButtonTargets.forEach(btn => {
        const orig = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = orig; }, 2000);
      });
    } catch (_) {
      // Fallback: select the first visible input
      const input = this.uriInputTargets.find(el => el.offsetParent !== null);
      if (input) { input.select(); try { document.execCommand('copy'); } catch (_) {} }
    }
  }

  _setStatus(msg) {
    this.statusTargets.forEach(el => { el.textContent = msg; });
  }

  _setRemoteStatus(msg) {
    if (this.hasRemoteStatusTarget) this.remoteStatusTarget.textContent = msg;
  }
}


