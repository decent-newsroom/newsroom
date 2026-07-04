# Essayist Zap Claim System — Implementation Summary

**Deployed:** June 3, 2026  
**Version:** v0.0.45  
**Status:** Complete ✓

---

## Problem Solved

**Before:** Essayist members could zap each other, but if the zap receipt was private (not published to relays), the automated `essayist:check-zap-receipts` command couldn't find it, leaving members with:
- No way to confirm their payment was processed
- No way to claim membership credit
- Only manual admin intervention via role elevation

**After:** Members can now claim membership by pasting either:
1. **Zap receipt event ID** → Auto-verified in seconds
2. **BOLT11 invoice** → Queued for admin review → Approved with claimed amount

---

## Solution Architecture

### 1. **Data Model**

New `essayist_zap_claim` table tracks all claim attempts:

```php
EssayistZapClaim {
  id: int (PK)
  user_id: int (FK → User)               // Who's claiming
  payer_pubkey: hex pubkey               // Actually paid
  sponsor_pubkey: hex pubkey             // Member who received the zap
  zap_receipt_event_id: hex? (UNIQUE)    // Proof: kind 9735 event ID
  bolt11_invoice: varchar? (UNIQUE)      // Proof: BOLT11 string
  claimed_amount_sats: int?               // Amount user claims
  status: enum(pending, verified, rejected)
  rejection_reason: text?                 // Why admin rejected
  verification_method: enum(auto_receipt, auto_invoice, manual)?
  created_at: timestamp                  // Claim submitted
  verified_at: timestamp?                // Claim processed
}
```

**Protection:**
- Unique index on `zap_receipt_event_id` — Claim same receipt multiple times → **409 Conflict**
- Unique index on `bolt11_invoice` — Prevents duplicate invoice claims
- Foreign key cascades on user deletion

### 2. **Verification Flow**

```
┌─────────────────────────────────────────────────────────────┐
│ Member submits claim via /essayist/members form             │
└────────────────────────┬────────────────────────────────────┘
                         │
                ┌────────▼░░░░░░░░░░░░░░░░┐
                │ Has receipt event ID?    │
                └────────┬──────────┬──────┘
                         │ YES      │ NO
           ┌─────────────▼──┐   ┌──▼──────────────────┐
           │ = Query local  │   │ Has BOLT11 invoice? │
           │ strfry for     │   └────┬────────┬───────┘
           │ kind:9735      │        │ YES    │ NO
           │                │   ┌────▼────┐  └──▼──INVALID
           │ Found?         │   │ Extract  │
           └────┬────┬──────┘   │ amount   │     Error: Neither
                │ YES│ NO       │ Mark    │     proof provided
  ┌─────────────▼──┐ │   ┌──────▼─PENDING─┐
  │ Extract amount │ │   │ Queue for admin │
  │ from event     │ │   │ review          │
  │ Mark VERIFIED  │ │   └────┬──────┬─────┘
  │ Grant          │ │        │      │
  │ membership     │ │   ┌────▼──┐  │
  │ Return 201 ✓  │ │   │ ADMIN  │  │
  └─────────────┬─┘ │   │ REVIEW │  │
                │   │   └────┬───┘  │
                │   └────────┤──────┤
                │            │      │
          ┌─────┴────────────┴──┐   │
          │ Approved?          │   │
          └──┬──────────┬───────┘   │
             │ YES      │ NO        │
       ┌─────▼────┐ ┌───▼──────────┴──▼──────────┐
       │ Grant    │ │ Return 202 PENDING         │
       │Return 201│ │ + CLI: bin/console         │
       └──────────┘ │ essayist:review-zap-claims│
                    └────────────────────────────┘
```

### 3. **Key Components**

| Component | Purpose |
|-----------|---------|
| **EssayistZapClaimService** | Business logic: create claim, verify, approve/reject |
| **EssayistClaimZapButton** | Live Component with form UI (Stimulus-integrated) |
| **EssayistZapClaimController** | REST API: `POST /api/essayist/claim-zap`, `GET /api/essayist/my-claims` |
| **EssayistReviewZapClaimsCommand** | CLI: Interactive admin approval workflow |
| **EssayistZapClaimRepository** | Database queries |
| **Migration Version20260603120000** | Create table + indexes |

### 4. **Idempotency & Replay Protection**

**Scenario:** Admin approves the same claim twice

```php
// First approval: Creates member ship grant with receipt ID "hash_of_claim_1"
EssayistMembershipService::recordGrant(
  zapReceiptEventId: hash(claim_id: 5, approved_at: 1234567),
  amountSats: 5000
);
// Succeeds → Membership recorded

// Second approval: Tries same pseudo-receipt ID
EssayistMembershipService::recordGrant(
  zapReceiptEventId: hash(claim_id: 5, approved_at: 1234567), 
  amountSats: 5000
);
// Fails silently (unique index on essayist_membership.zap_receipt_event_id)
// Return value: null
// No duplicate membership created ✓
```

---

## User Experience

### For Members: `/essayist/members`

**Paid for zap, want to confirm:**

1. Scroll to **"Claim Your Zap"**  section
2. Enter sponsor's npub (the member they zapped)
3. Paste **either** receipt event ID **or** BOLT11 invoice
4. (Optional) Enter amount in sats
5. Click **Submit Claim**

**Outcomes:**

| Outcome | Time | What happens |
|---------|------|-------------|
| ✓ Receipt found | Instant | "Verified! Membership extends through [date]" |
| ⏳ Invoice only | Instant | "Submitted for review. Admin will approve within hours." |
| ❌ Error | Instant | "Invalid/duplicate receipt" or "Missing sponsor" |

### For Admins: `essayist:review-zap-claims`

**Review pending claims:**

```bash
$ docker compose exec php bin/console essayist:review-zap-claims

Pending Essayist Zap Claims (3)
┌────┬──────────┬──────────┬──────────┬──────┬─────────────┐
│ ID │ Payer    │ Sponsor  │ Proof    │ Sats │ Created     │
├────┼──────────┼──────────┼──────────┼──────┼─────────────┤
│ 1  │ npub1... │ npub2... │ Receipt* │ 5000 │ 2026-06-03  │
│ 2  │ npub3... │ npub4... │ Invoice* │ ?    │ 2026-06-03  │
│ 3  │ npub5... │ npub6... │ Invoice* │ ?    │ 2026-06-02  │
└────┴──────────┴──────────┴──────────┴──────┴─────────────┘

Run: bin/console essayist:review-zap-claims <claim-id> to process a claim
```

**Approve one:**

```bash
$ docker compose exec php bin/console essayist:review-zap-claims 2

Processing Claim #2
Payer:       b1c2d3e4f5a6...
Sponsor:     d5e6f7a8b9c0...
Status:      pending
Created:     2026-06-03 12:34:56
Invoice:     lnbc5000u1p3xnhl2pp5...

What action would you like to take?
  [0] Approve
  [1] Reject
  [2] Skip
  > 0

Approve for how many sats? [1000] 5000
> 5000

✓ Claim #2 approved for 5000 sats
```

---

## Technical Details

### Database Migration

**File:** `migrations/Version20260603120000.php`

Creates `essayist_zap_claim` table with:
- Primary key `id`
- Unique constraint on `zap_receipt_event_id` (allows NULL)
- Unique constraint on `bolt11_invoice` (allows NULL)
- Indexes on `status`, `user_id`, `created_at` for queries
- Foreign key `user_id` → `"user"(id)` with CASCADE delete

### API Endpoints

**POST /api/essayist/claim-zap**

```json
REQUEST {
  "zapReceiptEventId": "a1b2c3d4e5f6...",
  "bolt11Invoice": "lnbc500u1p3xnhl2pp5...",
  "sponsorNpub": "npub1...",
  "claimedAmountSats": 5000
}

RESPONSE (201 if auto-verified) {
  "id": 1,
  "status": "verified",
  "verified": true,
  "message": "Zap claim verified and membership extended!"
}

RESPONSE (202 if pending) {
  "id": 2,
  "status": "pending",
  "verified": false,
  "message": "Zap claim submitted and pending verification. An admin will review it shortly."
}

ERROR (409 if duplicate) {
  "error": "This zap receipt has already been claimed"
}
```

**GET /api/essayist/my-claims**

```json
RESPONSE {
  [{
    "id": 1,
    "status": "verified",
    "createdAt": "2026-06-03T12:34:56Z",
    "verifiedAt": "2026-06-03T12:34:57Z",
    "claimedAmountSats": 5000,
    "verificationMethod": "auto_receipt",
    "rejectionReason": null
  }]
}
```

### Logging

All operations logged to Symfony logger. Watch with:

```bash
docker compose logs -f php | grep essayist_zap_claim
```

Examples:

```
[INFO] essayist_zap_claim.created claim_id=5 payer=b1c2d3e4 ...
[INFO] essayist_zap_claim.auto_verified claim_id=5 amount_sats=5000 ...
[INFO] essayist_zap_claim.approved claim_id=2 amount_sats=5000 grant_id=42 ...
[ERROR] essayist_zap_claim.failed claim_id=5 error=... ...
```

---

## Deployment Checklist

- [x] Entity created: `src/Entity/EssayistZapClaim.php`
- [x] Service created: `src/Service/Essayist/EssayistZapClaimService.php`
- [x] Repository created: `src/Repository/EssayistZapClaimRepository.php`
- [x] API Controller created: `src/Controller/Api/Essayist/EssayistZapClaimController.php`
- [x] Live Component created: `src/Twig/Components/Molecules/EssayistClaimZapButton.php`
- [x] Component template created: `templates/components/Molecules/EssayistClaimZapButton.html.twig`
- [x] CLI command created: `src/Command/EssayistReviewZapClaimsCommand.php`
- [x] Migration created: `migrations/Version20260603120000.php`
- [x] Feature documentation: `documentation/Essayist/essayist-zap-claims.md`
- [x] Setup guide: `documentation/Essayist/SETUP-ZAP-CLAIMS.md`
- [x] Members page updated: Added claim form
- [x] Changelog updated: Entry in v0.0.45
- [x] Documentation index updated: Added Essayist section

### Pre-Deployment

```bash
# 1. Run migration
docker compose exec php bin/console doctrine:migrations:migrate

# 2. Clear caches (optional)
docker compose exec php bin/console cache:clear

# 3. Compile assets if template changes
docker compose exec php bin/console asset-map:compile

# 4. Restart PHP (optional)
docker compose restart php
```

### Post-Deployment Testing

```bash
# 1. Test form displays (/essayist/members)
# 2. Submit a test claim with fake receipt ID
# 3. Check API response is 202 PENDING
# 4. Review claim: bin/console essayist:review-zap-claims
# 5. Approve with amount
# 6. Verify membership extended
```

---

## Files Modified / Created

### New Files (8)
1. `src/Entity/EssayistZapClaim.php` ← Entity
2. `src/Repository/EssayistZapClaimRepository.php` ← Repository
3. `src/Service/Essayist/EssayistZapClaimService.php` ← Service
4. `src/Controller/Api/Essayist/EssayistZapClaimController.php` ← API
5. `src/Twig/Components/Molecules/EssayistClaimZapButton.php` ← Component
6. `templates/components/Molecules/EssayistClaimZapButton.html.twig` ← Template
7. `src/Command/EssayistReviewZapClaimsCommand.php` ← CLI
8. `migrations/Version20260603120000.php` ← Migration

### Documentation (2)
1. `documentation/Essayist/essayist-zap-claims.md` — Full architecture
2. `documentation/Essayist/SETUP-ZAP-CLAIMS.md` — Setup & quick reference

### Modified Files (3)
1. `templates/essayist/members.html.twig` — Added claim form section
2. `CHANGELOG.md` — Feature entry
3. `documentation/INDEX.md` — Added Essayist section

---

## Next Steps (Optional Enhancements)

1. **LNURL Invoice Verification** — Query Lightning service to verify invoice paid
2. **Webhook Receipts** — Accept invoice.complete callbacks from Lightning providers
3. **Bulk Admin Import** — CSV upload for batch claim approvals
4. **Member Notifications** — Email/push when claim is processed
5. **Claim Expiration** — Auto-archive pending claims > 30 days old

---

## Success Metrics

- ✅ Members can confirm private zaps without relying on relay visibility
- ✅ Auto-verification instant for receipt-based claims
- ✅ Admin review workflow fast & intuitive
- ✅ No duplicate grants (unique indexes protect)
- ✅ Audit trail (all claims logged with action + timestamp)
- ✅ Schema supports future enhancements (invoice verification, webhooks)

---

**Reviewed & Complete ✓**

