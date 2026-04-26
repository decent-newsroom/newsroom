// Shared signer manager for Nostr signers (remote and extension)
//
// IMPORTANT: nostr-tools imports are dynamic (lazy) so that controllers which
// only need lightweight helpers (clearRemoteSignerSession, getRemoteSignerSession)
// do not force the browser to download the full nostr-tools / SimplePool bundle
// on every page load.

const REMOTE_SIGNER_KEY = 'amber_remote_signer';
const PENDING_SYNC_KEY  = 'nip46_server_sync_pending'; // set after login; cleared once server registration succeeds
const MAX_RETRIES = 3;
const RETRY_DELAY_MS = 2000;
const CONNECTION_TIMEOUT_MS = 60000; // 60 seconds - event may arrive after EOSE

let remoteSigner = null;
let remoteSignerPromise = null;

export async function getSigner(retryCount = 0) {
  // If remote signer session is active, use it
  const session = getRemoteSignerSession();
  console.log('[signer_manager] getSigner called, session exists:', !!session, 'retry:', retryCount);
  if (session) {
    if (remoteSigner) {
      console.log('[signer_manager] Returning cached remote signer');
      return remoteSigner;
    }
    if (remoteSignerPromise) {
      console.log('[signer_manager] Returning existing connection promise');
      return remoteSignerPromise;
    }

    console.log('[signer_manager] Recreating BunkerSigner from stored session...');
    remoteSignerPromise = createRemoteSignerFromSession(session)
      .then(signer => {
        remoteSigner = signer;
        console.log('[signer_manager] Remote signer successfully recreated and cached');
        return signer;
      })
      .catch(async (error) => {
        console.error('[signer_manager] Remote signer creation failed:', error);
        remoteSignerPromise = null;

        // Retry connection instead of clearing session
        if (retryCount < MAX_RETRIES) {
          console.log(`[signer_manager] Retrying connection (${retryCount + 1}/${MAX_RETRIES}) in ${RETRY_DELAY_MS}ms...`);
          await new Promise(resolve => setTimeout(resolve, RETRY_DELAY_MS));
          return getSigner(retryCount + 1);
        }

        // After all retries failed, throw error but DON'T clear session
        // User can manually retry or reconnect
        console.error('[signer_manager] All connection attempts failed. Remote signer may be offline.');
        throw new Error('Remote signer connection failed after ' + MAX_RETRIES + ' attempts. Please ensure Amber is running and try again.');
      });
    return remoteSignerPromise;
  }
  // Fallback to browser extension ONLY if no remote session
  console.log('[signer_manager] No remote session, checking for browser extension');
  if (window.nostr && typeof window.nostr.signEvent === 'function') {
    console.log('[signer_manager] Using browser extension');
    return window.nostr;
  }
  throw new Error('No signer available');
}

export function setRemoteSignerSession(session) {
  localStorage.setItem(REMOTE_SIGNER_KEY, JSON.stringify(session));
  // Mark that we still need to sync the session credentials to the server.
  // The /api/nostr-connect/session POST made right after login often returns 401
  // because the PHP session hasn't been committed to Redis yet at that exact
  // moment.  syncServerSessionIfPending() (called on every turbo:load) retries
  // the registration once the user is fully authenticated after the page reload.
  localStorage.setItem(PENDING_SYNC_KEY, '1');
}

/**
 * Clear the remote signer session from localStorage and close connections
 * WARNING: Only call this on explicit logout - NOT on page navigation/disconnect
 * The whole point of session persistence is to survive page reloads
 */
export function clearRemoteSignerSession() {
  console.log('[signer_manager] Clearing remote signer session');
  localStorage.removeItem(REMOTE_SIGNER_KEY);
  localStorage.removeItem(PENDING_SYNC_KEY);
  try { remoteSigner?.close?.(); } catch (_) {}
  remoteSigner = null;
  remoteSignerPromise = null;
}

export function getRemoteSignerSession() {
  const raw = localStorage.getItem(REMOTE_SIGNER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

/**
 * POST the stored NIP-46 session credentials to /api/nostr-connect/session if a
 * registration is still pending (flag set in setRemoteSignerSession).
 *
 * Called on every turbo:load so that a failed pre-reload attempt (which gets 401
 * because the PHP session hasn't been committed to Redis yet) is retried
 * automatically once the user is authenticated on the reloaded page.
 */
export async function syncServerSessionIfPending() {
  if (!localStorage.getItem(PENDING_SYNC_KEY)) return;

  const session = getRemoteSignerSession();
  if (!session?.privkey) return;

  const bunkerPubkey  = session.bunkerPointer?.pubkey;
  const bunkerRelays  = session.bunkerPointer?.relays?.length
    ? session.bunkerPointer.relays
    : (session.relays ?? []);

  if (!bunkerPubkey || !bunkerRelays.length) return;

  try {
    const resp = await fetch('/api/nostr-connect/session', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        clientPrivkeyHex: session.privkey,
        bunkerPubkeyHex:  bunkerPubkey,
        bunkerRelays:     bunkerRelays,
      }),
    });

    if (resp.ok) {
      // Registration succeeded — no need to retry on subsequent page loads.
      localStorage.removeItem(PENDING_SYNC_KEY);
      console.log('[signer_manager] NIP-46 server session registered successfully');
    } else if (resp.status === 400) {
      // Bad request — invalid stored data, stop retrying.
      localStorage.removeItem(PENDING_SYNC_KEY);
      console.warn('[signer_manager] NIP-46 server session rejected (400), clearing pending flag');
    }
    // 401 → user not authenticated yet; keep the flag and retry on next turbo:load.
  } catch (_) {
    // Network error — keep the flag for next page load.
  }
}

// Create BunkerSigner from stored session
// Uses fromBunker() for reconnection with stored BunkerPointer
// Falls back to fromURI() for legacy sessions
async function createRemoteSignerFromSession(session) {
  console.log('[signer_manager] ===== Recreating BunkerSigner from session =====');

  // Lazy-load nostr-tools only when actually reconnecting a remote signer
  const { SimplePool, finalizeEvent } = await import('nostr-tools');
  const { BunkerSigner } = await import('nostr-tools/nip46');
  const { hexToBytes } = await import('nostr-tools/utils');

  const MAX_RETRIES = 3;
  let lastError = null;

  for (let attempt = 1; attempt <= MAX_RETRIES; attempt++) {
    try {
      let signer;

      console.log(`[signer_manager] Reconnection attempt ${attempt}/${MAX_RETRIES}`);

      // PREFERRED: Use fromBunker() with stored BunkerPointer
      // Per nostr-tools docs: fromBunker returns immediately, then call connect()
      if (session.bunkerPointer) {
        console.log('[signer_manager] Using fromBunker() with stored BunkerPointer');
        console.log('[signer_manager] BunkerPointer pubkey:', session.bunkerPointer.pubkey);
        console.log('[signer_manager] BunkerPointer relays:', session.bunkerPointer.relays);

        const secretKey = hexToBytes(session.privkey);
        const pool = new SimplePool({
          enableReconnect: true,
          automaticallyAuth: () => (evt) => finalizeEvent(evt, secretKey)
        });

        signer = BunkerSigner.fromBunker(secretKey, session.bunkerPointer, { pool });

        console.log('[signer_manager] ✅ BunkerSigner created from pointer');
        console.log('[signer_manager] Calling connect() to re-establish relay subscription...');
        await signer.connect();
        console.log('[signer_manager] ✅ Connected to remote signer!');
      }
      // LEGACY: Fallback to fromURI() for old sessions without bunkerPointer
      else if (session.uri) {
        console.log('[signer_manager] ⚠️  Using legacy fromURI() pattern');

        const secretKey = hexToBytes(session.privkey);
        const pool = new SimplePool({
          enableReconnect: true,
          automaticallyAuth: () => (evt) => finalizeEvent(evt, secretKey)
        });

        // fromURI is async — it subscribes and waits for the bunker's connect response.
        // Already fully connected when it resolves, no connect() call needed.
        signer = await BunkerSigner.fromURI(
          secretKey,
          session.uri,
          { pool },
          CONNECTION_TIMEOUT_MS
        );
        console.log('[signer_manager] ✅ BunkerSigner created from URI!');
      } else {
        throw new Error('Session missing both bunkerPointer and uri');
      }

      // Verify the signer works
      try {
        console.log('[signer_manager] Testing signer with getPublicKey...');
        const testPromise = signer.getPublicKey();
        const timeoutPromise = new Promise((_, reject) =>
          setTimeout(() => reject(new Error('Signer verification timeout')), 10000)
        );
        const pubkey = await Promise.race([testPromise, timeoutPromise]);
        console.log('[signer_manager] ✅ Signer verified! Pubkey:', pubkey);
        return signer;
      } catch (testError) {
        console.error('[signer_manager] ❌ Signer test failed:', testError);
        try { signer.close(); } catch (_) {}
        throw new Error('Signer created but failed verification: ' + testError.message);
      }
    } catch (error) {
      lastError = error;
      console.error(`[signer_manager] ❌ Reconnection attempt ${attempt}/${MAX_RETRIES} failed:`, error.message);

      if (attempt === MAX_RETRIES) {
        console.error('[signer_manager] All reconnection attempts failed');
        remoteSigner = null;
        remoteSignerPromise = null;
        throw new Error(`Failed to reconnect after ${MAX_RETRIES} attempts: ${error.message}`);
      }

      const retryDelay = RETRY_DELAY_MS * Math.pow(2, attempt - 1);
      console.log(`[signer_manager] Retrying in ${retryDelay}ms...`);
      await new Promise(resolve => setTimeout(resolve, retryDelay));
    }
  }
}
