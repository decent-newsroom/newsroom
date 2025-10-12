// Shared signer manager for Nostr signers (remote and extension)
import { SimplePool } from 'nostr-tools';
import { BunkerSigner } from 'nostr-tools/nip46';

const REMOTE_SIGNER_KEY = 'amber_remote_signer';

let remoteSigner = null;
let remoteSignerPromise = null;
let remoteSignerPool = null;

export async function getSigner() {
  // If remote signer session is active, use it
  const session = getRemoteSignerSession();
  if (session) {
    if (remoteSigner) return remoteSigner;
    if (remoteSignerPromise) return remoteSignerPromise;
    remoteSignerPromise = createRemoteSigner(session).then(signer => {
      remoteSigner = signer;
      return signer;
    });
    return remoteSignerPromise;
  }
  // Fallback to browser extension
  if (window.nostr && typeof window.nostr.signEvent === 'function') {
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

async function createRemoteSigner(session) {
  remoteSignerPool = new SimplePool();
  return await BunkerSigner.fromURI(session.privkey, session.uri, { pool: remoteSignerPool });
}

