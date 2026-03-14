# Zaps (Lightning Payments)

## Overview

Zaps are Nostr kind 9735 events representing Lightning payments. The app supports zap buttons on articles and zap splits for distributing payments to multiple recipients.

## Zap Button

The `ZapButton` Twig component (`src/Twig/Components/Molecules/ZapButton.php`) renders a Lightning payment button on article pages. It resolves the author's `lud16` Lightning address and generates an invoice via LNURL.

### Flow

1. User clicks zap button → LNURL resolve via `LNURLResolver`
2. Invoice generated with optional zap request (NIP-57)
3. QR code displayed via `QRGenerator`
4. Payment confirmation tracked via zap receipt events (kind 9735)

## Zap Splits (NIP-57)

Authors can configure multiple payment recipients in the editor's advanced metadata panel. Each recipient gets a weight, and percentages are calculated automatically.

### Configuration

- DTOs: `src/Dto/ZapSplit.php` — recipient pubkey, relay hint, weight
- Builder: `NostrEventBuilder` generates `zap` tags with calculated percentages
- UI: `advanced_metadata_controller.js` — dynamic add/remove rows, live percentage display, "Distribute Equally" action

## Key Files

| Component | File |
|-----------|------|
| Zap button | `src/Twig/Components/Molecules/ZapButton.php` |
| LNURL resolver | `src/Service/LNURLResolver.php` |
| QR generator | `src/Service/QRGenerator.php` |
| Zap split DTO | `src/Dto/ZapSplit.php` |

