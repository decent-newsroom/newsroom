# Essayist Zap Claims & Manual Verification

**Last Updated:** June 2026  
**Version:** v0.0.45+  
**Status:** Active

## Overview

When Essayist members pay other members, automatic processing works only when a verifiable proof is available locally (payer proof or relay-visible receipt). If zaps are private and payer wallets expose only invoice strings, the system needs recipient confirmation.

The **Zap Claim & Verification** system solves this by allowing members to manually claim their zap and providing a verification workflow.

## How It Works

### 1. Member Claims a Zap

On `/essayist/members`, logged-in members see a "Claim Your Zap" form where they can:

- Paste **BOLT11 + payment preimage** (trustless payer-side proof when wallet supports it)
- OR paste their **zap receipt event ID** (kind 9735) — if available from a relay/client
- OR paste only **BOLT11 invoice** — pending until recipient/admin confirms
- Optionally enter the amount in sats

### 1a. Automatic pending-capture from members-page Zap dialog

On `/essayist/members`, the `ZapButton` runs with `enableMembershipClaim=true`.
When a payer generates an invoice and closes the dialog, the app auto-creates a pending `essayist_zap_claim` row (payer + sponsor + bolt11) and shows a local "pending recipient confirmation" hint under the button.

If payment fails or user cancels intent, the payer can click **Discard** to void/delete that pending claim before recipient/admin confirmation.

### 2. Automatic Verification (if Receipt is Available)

If the member provides a **zap receipt event ID** that exists in the local Nostr relay:

- System looks up the kind:9735 event
- Extracts the amount from the event's `amount` tag
- Fetches the receipt `created_at` timestamp (zap time)
- Calls `EssayistMembershipService::recordGrant()` to extend membership
- Claim is marked **VERIFIED** immediately

### 3. Recipient Confirmation (PaymentSuperchats-style fallback)

If the member only provides a **BOLT11 invoice** and no receipt/preimage can be verified:

- Claim is marked **PENDING**
- The recipient member (sponsor) sees pending claims on `/essayist/members`
- Recipient confirms payment, optionally providing a kind `9741` attestation event id
- Confirmation grants/extends membership with `verification_method = recipient_attestation`

### 4. Manual Admin Verification (backup)

If recipient confirmation is not available in time:

- Claim is marked **PENDING**
- Admins review pending claims via `bin/console essayist:review-zap-claims`
- Admin can **approve** (specifying the amount) or **reject** (providing a reason)
- On approval, membership is granted using the specified amount

## Architecture

### Database

**Table:** `essayist_zap_claim`

Tracks one row per claim attempt, whether verified or pending:

```
id                    INT (PK)
user_id               INT (FK → User)
payer_pubkey          VARCHAR(64)         -- Hex pubkey of the payer
sponsor_pubkey        VARCHAR(64)         -- Hex pubkey of the recipient/sponsor
zap_receipt_event_id  VARCHAR(64) NULL    -- Kind 9735 event ID (if provided)
bolt11_invoice        VARCHAR(1024) NULL  -- BOLT11 invoice (if provided)
payment_preimage      VARCHAR(64) NULL    -- payer-side proof, if wallet exposes preimage
claimed_amount_sats   INT NULL            -- Amount claimed/approved
status                VARCHAR(32)         -- 'pending' | 'verified' | 'rejected'
rejection_reason      TEXT NULL           -- Reason if rejected
verification_method   VARCHAR(32) NULL    -- 'preimage' | 'auto_receipt' | 'recipient_attestation' | 'manual'
recipient_attestor_pubkey      VARCHAR(64) NULL
recipient_attestation_event_id VARCHAR(64) NULL
recipient_attestation_note     TEXT NULL
created_at            TIMESTAMP
verified_at           TIMESTAMP NULL
```

Unique indices prevent duplicate claims by receipt ID or invoice.

### Services

**`EssayistZapClaimService`** (`src/Service/Essayist/EssayistZapClaimService.php`)

Main service for claim lifecycle:

- `createClaim(...)` — Create a new pending claim
- `verifyClaim(claim)` — Attempt auto-verification:
  - Verifies `sha256(preimage) == payment_hash` when preimage+invoice provided
  - Looks up the zap receipt by event ID
  - Extracts amount and timestamp from receipt when available
  - Calls `EssayistMembershipService::recordGrant()`
  - Returns `true` if verified, `false` if recipient/admin action is needed
- `attestByRecipient(...)` — Recipient confirms payer claim (superchat-style)
- `approveClaim(claim, amountSats)` — Admin approval:
  - Creates a pseudo-receipt event ID (deterministic hash)
  - Records the grant via membership service
  - Marks claim as verified
- `rejectClaim(claim, reason)` — Admin rejection

### API Endpoint

**`POST /api/essayist/claim-zap`** (`src/Controller/Api/Essayist/EssayistZapClaimController.php`)

Requires authentication. Accepts:

```json
{
  "zapReceiptEventId": "...",       // optional
  "bolt11Invoice": "...",           // optional
  "paymentPreimage": "...",         // optional (requires bolt11Invoice)
  "sponsorNpub": "...",             // required
  "claimedAmountSats": 5000         // optional
}
```

Returns:

- `201 CREATED` + verified success message if auto-verified
- `202 ACCEPTED` + pending message if submitted for review
- `409 CONFLICT` if duplicate receipt ID
- `4xx` for validation errors

**`GET /api/essayist/my-claims`** — Fetch user's claim history (pending, verified, rejected)

### Twig Live Component

**`EssayistClaimZapButton`** (`src/Twig/Components/Molecules/EssayistClaimZapButton.php`)

Renders a form with:

- Textarea for zap receipt event ID
- Textarea for BOLT11 invoice
- Input for claimed amount
- Submit button that calls the API endpoint
- Displays result (success/error) with appropriate messaging

State machine: `form` → `submitting` → `success` or `error` → reset

### CLI Commands

**`essayist:review-zap-claims`** (`src/Command/EssayistReviewZapClaimsCommand.php`)

**List all pending claims:**
```bash
docker compose exec php bin/console essayist:review-zap-claims
```

Output: Table showing claim ID, payer, sponsor, proof type, amount, created date.

**Process a specific claim (interactive):**
```bash
docker compose exec php bin/console essayist:review-zap-claims 5
```

Displays claim details and prompts: Approve | Reject | Skip

**Approve directly:**
```bash
docker compose exec php bin/console essayist:review-zap-claims 5 --amount=5000
```

**Reject directly:**
```bash
docker compose exec php bin/console essayist:review-zap-claims 5 --reject="Invoice amount too low"
```

## User Flow

### Scenario 1: Private Zap Receipt Found Locally

1. User pays via `/essayist/members` TipButton → generates invoice, paid
2. Lightning service publishes kind:9735 receipt, but user marks it private
3. User goes to `/essayist/members` → "Claim Your Zap" form
4. Pastes zap receipt event ID
5. System finds the event in local strfry, extracts amount, auto-verifies
6. **✓ Membership immediately extended**

### Scenario 2: Private Zap, No Receipt Access

1. User pays invoice but zap receipt is private and unavailable
2. User only has the BOLT11 invoice (from their wallet)
3. User goes to `/essayist/members` → "Claim Your Zap" form
4. Pastes BOLT11 invoice (and optionally some sats estimate)
5. System can't auto-verify without receipt
6. **→ Claim marked PENDING**
7. Admin reviews via `essayist:review-zap-claims`, verifies BOLT11 format, approves
8. **✓ Membership extended**

### Scenario 3: Duplicate or False Claim

1. User submits claim for same receipt ID twice
2. **→ `409 CONFLICT`** "This zap receipt has already been claimed"
3. OR admin reviews claim with mismatched amount or invalid invoice
4. Admin rejects with specific reason
5. **→ User sees rejection notice and can contact support**

## Configuration

No new config parameters are required (reuses `essayist.membership.minimum_sats` from existing config).

## Logging

All claim operations are logged to the Symfony logger:

```
essayist_zap_claim.created       — New claim submitted
essayist_zap_claim.auto_verified — Claim auto-verified via receipt lookup
essayist_zap_claim.approved      — Admin approved claim
essayist_zap_claim.rejected      — Admin rejected claim
```

## Security Considerations

1. **No spoofing:** Receipt ID index + unique constraint prevents duplicate grants
2. **Replay protection:** Pseudo-receipt ID for manual approvals ensures even if admin approves the same claim twice, the membership service rejects the duplicate receipt ID
3. **Payer validation:** Claim is tied to the user's Nostr pubkey; can't claim a zap from someone else
4. **No external calls:** System doesn't query external Lightning APIs; claims are either found locally or require manual admin verification
5. **Audit trail:** Every claim is logged with method, timestamp, and admin actions

## Edge Cases

### What if a receipt is published to a relay later?

If a user initially couldn't access their receipt, but it later arrives on a relay:

- The receipt will flow through `EssayistZapReceiptWorkerCommand` as normal
- The unique index on `essayist_zap_claim.zap_receipt_event_id` (the claim row) won't conflict
- The unique index on `essayist_membership.zap_receipt_event_id` will reject the duplicate *membership* grant
- User sees their membership is already active ✓

### What if a user claims the same invoice twice?

The BOLT11 invoice has a unique index in the `essayist_zap_claim` table:

- Second claim with same invoice → `409 CONFLICT` immediately
- User is notified: "This invoice has already been claimed"

### What if an admin approves a claim after the real receipt arrives?

Both the real receipt and the admin approval create a membership grant but with different pseudo-receipt IDs:

- Real receipt: `essayist_zap_claim.zap_receipt_event_id = <actual event id>`
- Admin approval: `essayist_membership.zap_receipt_event_id = hash(<claim_id>, ...)` (pseudo)
- No conflict; user gets two grants for the same payment

**This is acceptable** — the second grant just extends membership a bit further — but can be mitigated by admins checking the local relay before approving.

## Future Enhancements

1. **Lightning invoice verification API:** Query LNURL issuer to check invoice status (requires trusted issuer key)
2. **Webhook receipts:** If members provide webhook callbacks, listen for `invoice.complete` events from their Lightning service
3. **Receipt sharing protocol:** Standardized way for wallets to export receipts as signed Nostr events
4. **Bulk claim processing:** Batch CSV import for admins to bulk-approve claims
5. **Member-facing claim history:** Dashboard showing past claims and their status

## Related Documentation

- `documentation/essayist.md` — Core Essayist system
- `documentation/essayist-home.md` — Member home page
- `src/Service/Essayist/EssayistMembershipService.php` — Membership grant logic
- `src/Command/EssayistZapReceiptWorkerCommand.php` — Automated receipt scanner

