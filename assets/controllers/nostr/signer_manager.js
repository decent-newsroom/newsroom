// Shared signer manager for Nostr signers (remote and extension)
import { SimplePool, finalizeEvent } from 'nostr-tools';
import { BunkerSigner } from 'nostr-tools/nip46';
import { hexToBytes } from 'nostr-tools/utils';

const REMOTE_SIGNER_KEY = 'amber_remote_signer';
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
}

/**
 * Clear the remote signer session from localStorage and close connections
 * WARNING: Only call this on explicit logout - NOT on page navigation/disconnect
 * The whole point of session persistence is to survive page reloads
 */
export function clearRemoteSignerSession() {
  console.log('[signer_manager] Clearing remote signer session');
  localStorage.removeItem(REMOTE_SIGNER_KEY);
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

// Create BunkerSigner from stored session
// Uses fromBunker() for reconnection with stored BunkerPointer
// Falls back to fromURI() for legacy sessions
async function createRemoteSignerFromSession(session) {
  console.log('[signer_manager] ===== Recreating BunkerSigner from session =====');

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
