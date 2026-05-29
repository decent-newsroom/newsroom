# Payment Targets (NIP-A3 — kind 10133, `payto:` Tips)

Status: shipped.

## Overview

This newsroom implements [NIP-A3](../NIP/A3.md) — `payto:` payment targets
(RFC-8905) as the kind `10133` replaceable event. It powers a new **Tip**
button that lets readers pay an author through any payment system the author
has declared, not just Lightning.

`Tips` are complementary to `Zaps` (NIP-57):

| Aspect             | Zap (NIP-57)                              | Tip (NIP-A3)                                            |
| ------------------ | ----------------------------------------- | ------------------------------------------------------- |
| Source of address  | `lud16` / `lud06` in kind 0 metadata      | `payto` tags in kind 10133                              |
| Payment systems    | Lightning only                            | Bitcoin, Lightning, Ethereum, Nano, Cash App, Venmo,    |
|                    |                                           | Revolut, Monero, *and any custom RFC-8905 scheme*       |
| User picks         | Just an amount                            | First a payment method, then (for Lightning) an amount  |
| Receipt on Nostr   | Kind 9735 zap receipt                     | None for non-Lightning; Lightning flow still emits 9735 |

Both buttons can coexist on the same author/article — the Zap button is
shown when a Lightning address is present in metadata, the Tip button is
shown whenever at least one payment target is available (including a
synthesized `lightning:lud16` fallback when only the kind 0 has a `lud16`
but no kind 10133 has been published yet).

## Event format

A NIP-A3 event uses kind `10133`, an empty `content`, and one `payto` tag
per payment target:

```json
{
  "kind": 10133,
  "content": "",
  "tags": [
    ["payto", "bitcoin", "bc1qxq66e0t8d7ugdecwnmv58e90tpry23nc84pg9k"],
    ["payto", "lightning", "alice@walletofsatoshi.com"],
    ["payto", "nano", "nano_1dctqbmqxfppo9pswbm6kg9d4s4mbraqn8i4m7ob9gnzz91aurmuho48jx3c"]
  ]
}
```

Validation rules implemented (`PaymentTargetService`):

- `type` must match `^[a-z0-9-]+$` and is normalized to lowercase.
- `authority` must be non-empty (trimmed).
- Duplicate `(type, authority)` pairs are dropped.
- Tags with fewer than 3 elements or unknown shape are skipped.
- Extra elements beyond `type` + `authority` are preserved on the DTO as
  `extra` for forward compatibility.

## Recognized types

Recognized types come with a friendly label, short label, and symbol used
for rendering. Unknown types still render with the raw type name and an
"unknown type" badge.

| type        | label             | short    | symbol |
| ----------- | ----------------- | -------- | ------ |
| `bitcoin`   | Bitcoin           | BTC      | ₿      |
| `cashme`    | Cash App          | Cash App | $      |
| `ethereum`  | Ethereum          | ETH      | Ξ      |
| `lightning` | Lightning Network | LBTC     | ⚡     |
| `monero`    | Monero            | XMR      | ɱ      |
| `nano`      | Nano              | XNO      | Ӿ      |
| `revolut`   | Revolut           | Revolut  |        |
| `venmo`     | Venmo             | Venmo    | $      |

## Sync & persistence

`PAYMENT_TARGETS = 10133` is wired into the same pipelines as every other
user-context kind:

- `KindsEnum::PAYMENT_TARGETS` — single source of truth for the kind.
- `KindBundles::USER_CONTEXT` — included in the bulk fetch on login,
  on settings backfill, and on profile sync.
- `SyncUserEventsHandler::SYNC_KINDS` — fetched from the user's
  NIP-65 relays during the async login sync.
- `SubscribeLocalUserContextCommand::SUBSCRIBE_KINDS` — picked up by the
  local-relay user-context worker so the projector persists incoming
  events into `event` table immediately.

The latest event per pubkey is the source of truth (kind 10133 is
replaceable per NIP-A3).

## UI surfaces

### Tip button (`Twig\Components\Molecules\TipButton`)

Live Component rendered next to the existing `ZapButton` on:

- `templates/pages/article.html.twig` (article header + bottom)
- `templates/partial/_author-section.html.twig` (author profile)

Behavior:

1. The component queries `PaymentTargetService::getForPubkey()` to load
   the recipient's payment targets. If there are none **and** the
   profile has a `lud16`, a synthetic `lightning` target is prepended so
   the user can still tip out of the box.
2. On modal open, the component performs a targeted relay lookup for kind
   `10133` only and resolves targets directly from that event, so recently
   updated payment methods are reflected without waiting for background sync
   cycles.
3. The trigger only renders when there is at least one target.
4. Clicking it opens a modal:
   - **select** — lists every payment target. Clicking one transitions to:
   - **lightning_input** — for `lightning` targets, classic amount + note
     form. Submitting runs the existing NIP-57 zap pipeline
     (`LNURLResolver` + `NostrSigner::buildZapRequest`) and shows a
     BOLT11 + QR.
   - **payto** — for everything else, shows the `payto://type/authority`
     URI as a clickable link plus a scannable QR. The raw `authority`
     and full URI are available as copyable inputs.

### Settings · Payments tab

`templates/settings/tabs/_payments.html.twig` adds a new "Payments" tab to
`/settings`. The user can:

- Add/remove rows containing a `type` and an `authority`.
- See the list of recognized RFC-8905 types in a collapsible section.
- Click "Sign and publish" — the Stimulus controller
  `nostr--nostr-settings-payment-targets` validates, deduplicates, signs
  the kind 10133 event with the configured signer (NIP-07 / NIP-46), and
  POSTs it to `api_settings_event_publish` (which already accepts every
  `KindBundles::USER_CONTEXT` kind).

## Files

```
src/
  Dto/PaymentTarget.php                                  # parsed target
  Enum/KindsEnum.php                                     # +PAYMENT_TARGETS = 10133
  Enum/KindBundles.php                                   # +USER_CONTEXT entry
  MessageHandler/SyncUserEventsHandler.php               # +SYNC_KINDS entry
  Command/SubscribeLocalUserContextCommand.php           # +SUBSCRIBE_KINDS entry
  Service/Nostr/PaymentTargetService.php                 # parser + repository facade
  Twig/Components/Molecules/TipButton.php                # live component

templates/
  components/Molecules/TipButton.html.twig
  settings/tabs/_payments.html.twig                      # settings tab
  settings/index.html.twig                               # tab nav
  pages/article.html.twig                                # tip button next to zap
  partial/_author-section.html.twig                      # tip button next to zap

assets/
  controllers/nostr/nostr_settings_payment_targets_controller.js
  styles/03-components/tip.css

translations/messages.en.yaml                            # tip.*, settings.payments.*
```

