# Relay Pool Lazy Loading

## Problem

The nostr-tools `SimplePool` (and the PHP-side `NostrRelayPool`) were being instantiated on every page, including pages that never interact with Nostr relays (e.g. static content, settings, admin views).

### JavaScript side

Two Stimulus controllers on the `UserMenu` component caused the full `nostr-tools` bundle (crypto, WebSocket, SimplePool) to be downloaded and parsed on every page:

1. **`utility--signer-modal`** was declared on the shared parent `<div>` wrapping both the logged-in and not-logged-in branches. Stimulus lazy-loaded the controller module on every page, pulling in `nostr-tools`, `nostr-tools/nip46`, and `nostr-tools/utils` via static top-level imports.

2. **`nostr--logout`** (present for logged-in users) imported `clearRemoteSignerSession` from `signer_manager.js`, which had static top-level imports of `SimplePool`, `BunkerSigner`, `finalizeEvent`, and `hexToBytes`. Even though `clearRemoteSignerSession` is a pure localStorage operation, loading the module pulled the entire dependency tree.

### PHP side

`NostrRelayPool` was not marked as a lazy Symfony service. Its constructor calls `RelayRegistry::getDefaultRelays()` to build the relay list and logs to the debug channel. Since it's injected transitively into `NostrClient` → `NostrRequestExecutor` → `RelaySetFactory`, any controller that type-hints `NostrClient` would trigger the full construction chain on instantiation — even if none of its methods were called during that request.

## Fix

### JavaScript

- **Moved `utility--signer-modal`** controller declaration from the shared parent div to only the `{% else %}` (non-logged-in) branch in `UserMenu.html.twig`. Logged-in users never see the signer modal.

- **Converted static `nostr-tools` imports to dynamic `import()`** in:
  - `signer_manager.js` — the top-level imports of `SimplePool`, `finalizeEvent`, `BunkerSigner`, `hexToBytes` are now loaded inside `createRemoteSignerFromSession()` only when a remote signer reconnection is actually needed.
  - `signer_modal_controller.js` — all nostr-tools imports are loaded inside `connectBunker()`, `_init()`, and `_attemptAuth()` methods.

- Lightweight exports (`clearRemoteSignerSession`, `getRemoteSignerSession`, `setRemoteSignerSession`) remain synchronous and don't trigger any nostr-tools loading.

### PHP

- Marked `NostrRelayPool`, `NostrClient`, and `NostrRequestExecutor` as `lazy: true` in `services.yaml`. Symfony generates ghost object proxies (via `symfony/var-exporter`) that defer constructor execution until a method is actually called.

## Impact

| Scenario | Before | After |
|----------|--------|-------|
| Logged-in user, any page | `nostr-tools` loaded (via `nostr--logout` → `signer_manager`) | Only `@hotwired/stimulus` + lightweight signer_manager module |
| Anonymous user, any page | `nostr-tools` loaded (via `utility--signer-modal`) | `nostr-tools` loaded only when login section is rendered |
| PHP: page not using relays | `NostrRelayPool` constructor runs, builds relay list, logs | Ghost proxy created, no constructor work |
| PHP: page using relays | Normal | First method call triggers construction (negligible overhead) |

