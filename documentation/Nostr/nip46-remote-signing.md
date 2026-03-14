# NIP-46 Remote Signing

## Overview

NIP-46 enables remote signing via a "bunker" — a separate application (like Amber on Android or nsec.app) that holds the user's private key. The Newsroom app communicates with the bunker over Nostr relays using encrypted messages.

## Login Flow

1. App generates a `nostrconnect://` URI with its public key and relay list
2. User scans the QR code (or pastes the URI) in their bunker app
3. Bunker sends an encrypted connect event to the relays
4. App receives the event, session is established
5. Session credentials saved to `localStorage` (`amber_remote_signer` key)

## Session Persistence

After initial pairing, `BunkerSigner.fromURI()` returns immediately — no re-handshake needed. Subsequent signing requests (publish, interests, etc.) reuse the stored session and work without user interaction (~2 seconds, relay latency only).

### Key Files

| Component | File |
|-----------|------|
| Signer manager | `assets/controllers/nostr/signer_manager.js` |
| Amber connect | `assets/controllers/nostr/amber_connect_controller.js` |
| Login controller | `assets/controllers/utility/login_controller.js` |
| Authenticator | `src/Security/NostrAuthenticator.php` |

## Relay Configuration

Signer relays are configured in `services.yaml` under `relay_registry.signer_relays`. The app subscribes to these plus common public relays for maximum coverage.

## Lessons Learned

- **Bunkers ignore the relay list in `nostrconnect://` URIs** and send connect events to their own default relays. Fix: subscribe to BOTH the URI relays AND common public relays (`nos.lol`, `relay.primal.net`, `relay.damus.io`, etc.) to ensure the connect event is caught.
- **`BunkerSigner.fromURI()` returns immediately** — it does not block for a handshake. The connect handshake only happens during the initial pairing. No retry logic or long timeouts needed for subsequent calls.
- **Session reconnection**: After a page reload, recreate the signer from stored session credentials. Call `getPublicKey()` as a quick verification before signing.
- **Timing**: Initial connection requires user approval in the bunker app (variable time). All subsequent operations should be near-instant.

