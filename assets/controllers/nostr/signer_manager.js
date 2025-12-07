// Shared signer manager for Nostr signers (remote and extension)
import { SimplePool } from 'nostr-tools';
import { BunkerSigner } from 'nostr-tools/nip46';

const REMOTE_SIGNER_KEY = 'amber_remote_signer';

let remoteSigner = null;
let remoteSignerPromise = null;
let remoteSignerPool = null;

export async function getSigner(_retrying = 0) {
  // If remote signer session is active, use it
  const session = getRemoteSignerSession();
  console.log('[signer_manager] getSigner called, session exists:', !!session);
  if (session) {
    if (remoteSigner) {
      console.log('[signer_manager] Returning cached remote signer');
      return remoteSigner;
    }
    if (remoteSignerPromise) {
      console.log('[signer_manager] Returning existing connection promise');
      return remoteSignerPromise;
    }

    console.log('[signer_manager] Recreating BunkerSigner from stored session (no connect needed)...');
    // According to nostr-tools docs: BunkerSigner.fromURI() returns immediately
    // After initial connect() during login, we can reuse the signer without reconnecting
    remoteSignerPromise = createRemoteSignerFromSession(session)
      .then(signer => {
        remoteSigner = signer;
        console.log('[signer_manager] Remote signer successfully recreated and cached');
        return signer;
      })
      .catch((error) => {
        console.error('[signer_manager] Remote signer creation failed:', error);
        remoteSignerPromise = null;
        // Clear stale session
        console.log('[signer_manager] Clearing stale remote signer session');
        clearRemoteSignerSession();
        // Fallback to browser extension if available
        if (window.nostr && typeof window.nostr.signEvent === 'function') {
          console.log('[signer_manager] Falling back to browser extension');
          return window.nostr;
        }
        throw new Error('Remote signer unavailable. Please reconnect Amber or use a browser extension.');
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

export function clearRemoteSignerSession() {
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
// According to nostr-tools: fromURI() returns immediately, no waiting for handshake
// The connect() was already done during initial login, so we can use the signer right away
async function createRemoteSignerFromSession(session) {
  console.log('[signer_manager] ===== Recreating BunkerSigner from session =====');
  console.log('[signer_manager] Session URI:', session.uri);
  console.log('[signer_manager] Session relays:', session.relays);

  // Reuse existing pool if available, otherwise create new one
  if (!remoteSignerPool) {
    console.log('[signer_manager] Creating new SimplePool for relays:', session.relays);
    remoteSignerPool = new SimplePool();
  } else {
    console.log('[signer_manager] Reusing existing SimplePool');
  }

  try {
    console.log('[signer_manager] Creating BunkerSigner from stored session...');
    // fromURI returns a Promise - await it to get the signer
    const signer = await BunkerSigner.fromURI(session.privkey, session.uri, { pool: remoteSignerPool });
    console.log('[signer_manager] ✅ BunkerSigner created! Testing with getPublicKey...');

    // Test the signer to make sure it works
    try {
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
