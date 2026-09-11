# Unfold Site Creation Signing

The Unfold administration form creates a kind `30078` AppData event that binds a sanitized subdomain, a selected magazine coordinate, and a theme. Its Stimulus controller is connected on every Turbo visit, so the sign action never uses the placeholder event rendered by Twig.

## Overview

Administrators create hosted magazine sites from `/admin/unfold/new`. The form validates its values, updates the coordinate feedback and event preview, and constructs the final event only when the administrator chooses **Sign & Publish**. The subdomain is passed only with that publish request; no global `fetch` behavior is modified.

## Architecture

### Flow

1. `admin--unfold-site` normalizes the subdomain and refreshes the preview when form values change.
2. At sign time it validates a `30040:<64-hex-pubkey>:<identifier>` magazine coordinate and builds a kind `30078` event with `d`, `a`, `theme`, and `alt` tags.
3. It calls the nested `nostr--nostr-single-sign` controller with the event and `{ subdomain }` as request fields.
4. The signing controller signs the event and includes those supplied fields at the top level of its JSON publish request.
5. `UnfoldSiteController::publish()` verifies and publishes the signed event, then persists the subdomain-to-coordinate mapping.

### Key files

| File | Role |
|---|---|
| `assets/controllers/admin/admin_unfold_site_controller.js` | Turbo-safe form state, validation, preview, and signing handoff |
| `assets/controllers/nostr/nostr_single_sign_controller.js` | Signs an explicit event and forwards scoped request fields |
| `templates/admin/unfold/new.html.twig` | Connects the form and nested signer controllers |
| `src/Controller/Administration/UnfoldSiteController.php` | Publishes AppData events and stores site mappings |

## Limitations / Known Issues

- The selected magazine must be a kind `30040` coordinate with a 64-character hexadecimal pubkey.

## Related NIPs / NKBIPs

- [NIP-78](../NIP/78.md) — AppData event kind `30078` used for Unfold configuration.
