// Shared signer manager for Nostr signers (remote and extension)
import { SimplePool } from 'nostr-tools';
import { BunkerSigner } from 'nostr-tools/nip46';
import { hexToBytes } from 'nostr-tools/utils';

const REMOTE_SIGNER_KEY = 'amber_remote_signer';
const MAX_RETRIES = 3;
const RETRY_DELAY_MS = 2000;

let remoteSigner = null;
let remoteSignerPromise = null;
let remoteSignerPool = null;

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
}

/**
 * Clear the remote signer session from localStorage and close connections
 * WARNING: Only call this on explicit logout - NOT on page navigation/disconnect
 * The whole point of session persistence is to survive page reloads
 */
export function clearRemoteSignerSession() {
  console.log('[signer_manager] Clearing remote signer session');
  localStorage.removeItem(REMOTE_SIGNER_KEY);
  remoteSigner = null;
  remoteSignerPromise = null;
  if (remoteSignerPool) {
    try { remoteSignerPool.close?.([]); } catch (_) {}
    remoteSignerPool = null;
  }
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

// Create BunkerSigner from stored session
// Uses fromBunker() for reconnection with stored BunkerPointer
// Falls back to fromURI() for legacy sessions
async function createRemoteSignerFromSession(session) {
  console.log('[signer_manager] ===== Recreating BunkerSigner from session =====');

  // Reuse existing pool if available, otherwise create new one
  if (!remoteSignerPool) {
    console.log('[signer_manager] Creating new SimplePool');
    remoteSignerPool = new SimplePool();
  } else {
    console.log('[signer_manager] Reusing existing SimplePool');
  }

  try {
    let signer;

    // NEW PATTERN: Use fromBunker() with stored BunkerPointer (preferred)
    if (session.bunkerPointer) {
      console.log('[signer_manager] Using fromBunker() with stored BunkerPointer');
      console.log('[signer_manager] BunkerPointer pubkey:', session.bunkerPointer.pubkey);
      console.log('[signer_manager] BunkerPointer relays:', session.bunkerPointer.relays);

      // fromBunker() is for reconnecting to an already-authorized bunker
      // It doesn't wait for a new connect message like fromURI() does
      // Convert hex privkey to Uint8Array
      signer = BunkerSigner.fromBunker(
        hexToBytes(session.privkey),
        session.bunkerPointer,
        { pool: remoteSignerPool }
      );

      console.log('[signer_manager] ✅ BunkerSigner created from pointer!');
    }
    // LEGACY PATTERN: Fallback to fromURI() for old sessions (backward compatibility)
    else if (session.uri) {
      console.log('[signer_manager] ⚠️  Using legacy fromURI() pattern (session has no bunkerPointer)');
      console.log('[signer_manager] Session URI:', session.uri);
      console.log('[signer_manager] Session relays:', session.relays);

      // fromURI returns a Promise - await it to get the signer
      // Convert hex privkey to Uint8Array
      signer = await BunkerSigner.fromURI(hexToBytes(session.privkey), session.uri, { pool: remoteSignerPool });
      console.log('[signer_manager] ✅ BunkerSigner created from URI!');

      // With fromURI, we need to call connect()
      console.log('[signer_manager] Calling connect() to establish relay connection...');
      await signer.connect();
      console.log('[signer_manager] ✅ Connected to remote signer!');
    } else {
      throw new Error('Session missing both bunkerPointer and uri');
    }

    // Test the signer to make sure it works
    try {
      console.log('[signer_manager] Testing signer with getPublicKey...');
      const pubkey = await signer.getPublicKey();
      console.log('[signer_manager] ✅ Signer verified! Pubkey:', pubkey);
      return signer;
    } catch (testError) {
      console.error('[signer_manager] ❌ Signer test failed:', testError);
      throw new Error('Signer created but failed verification: ' + testError.message);
    }
  } catch (error) {
    console.error('[signer_manager] ❌ Failed to create signer:', error);
    // Clean up on error
    if (remoteSignerPool) {
      try {
        console.log('[signer_manager] Closing pool after error');
        remoteSignerPool.close?.([]);
      } catch (_) {}
      remoteSignerPool = null;
    }
    remoteSigner = null;
    remoteSignerPromise = null;
    throw error;
  }
}
