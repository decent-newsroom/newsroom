# Essayist Zap Claim Implementation — Quick Setup

**Date:** June 3, 2026
**Version:** v0.0.45

## What's New

Members can now confirm private zaps by claiming them on `/essayist/members` with either:
- Their **zap receipt event ID** (kind 9735) for auto-verification
- Their **BOLT11 invoice** for admin review

## Files Created

### Core Implementation
1. **Entity**
   - `src/Entity/EssayistZapClaim.php` — Tracks pending/verified claims (new table)

2. **Repository**
   - `src/Repository/EssayistZapClaimRepository.php` — Database queries for claims

3. **Service**
   - `src/Service/Essayist/EssayistZapClaimService.php` — Verification logic, grant recording

4. **API Controller**
   - `src/Controller/Api/Essayist/EssayistZapClaimController.php`
     - `POST /api/essayist/claim-zap` — Submit a claim
     - `GET /api/essayist/my-claims` — View user's claims

5. **Twig Live Component**
   - `src/Twig/Components/Molecules/EssayistClaimZapButton.php` — Form component
   - `templates/components/Molecules/EssayistClaimZapButton.html.twig` — Form template

6. **Console Command**
   - `src/Command/EssayistReviewZapClaimsCommand.php` — Admin claim review (`essayist:review-zap-claims`)

7. **Database Migration**
   - `migrations/Version20260603120000.php` — Creates `essayist_zap_claim` table

### Documentation & Updates
8. **Documentation**
   - `documentation/Essayist/essayist-zap-claims.md` — Full feature documentation

9. **Templates Updated**
   - `templates/essayist/members.html.twig` — Added claim form section

10. **Changelog**
    - `CHANGELOG.md` — Added feature entry

## Deployment Steps

### 1. Run Database Migration
```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

### 2. Test the Feature
- Navigate to `/essayist/members` (logged in)
- Scroll to "Claim Your Zap"
- Fill form with sponsor npub + receipt ID or invoice
- Click Submit
- Check API response (201 auto-verified or 202 pending)

### 3. Admin Review (if needed
)
```bash
# List all pending claims
docker compose exec php bin/console essayist:review-zap-claims

# Process specific claim (interactive)
docker compose exec php bin/console essayist:review-zap-claims 5

# Approve directly
docker compose exec php bin/console essayist:review-zap-claims 5 --amount=5000

# Reject
docker compose exec php bin/console essayist:review-zap-claims 5 --reject="Invalid amount"
```

## How It Works

### Scenario 1: Receipt Available Locally (Auto-Verify)
1. User provides zap receipt event ID
2. System finds kind:9735 event in local strfry relay
3. Extracts amount + timestamp
4. Records membership grant immediately
5. **Response: 201 CREATED** "Membership extended!"

### Scenario 2: Receipt Unavailable (Manual Admin Review)
1. User provides BOLT11 invoice
2. System can't verify without external API
3. Claim marked **PENDING**
4. Admin reviews via CLI command
5. Admin approves amount
6. **Membership granted**

### Scenario 3: Duplicate Protection
1. Unique index on `zap_receipt_event_id` + `bolt11_invoice` in DB
2. Second claim with same proof → `409 CONFLICT`
3. User notified: "Already claimed"

## Translation Keys Added

Add to all 5 locale files (`translations/messages.*.yaml`):

```yaml
essayist:
  claim:
    heading: 'Claim Your Zap'
    description: 'Paid for a membership but don''t see it reflected? Paste your zap receipt or invoice below to claim credit.'
```

(Currently using fallback English text in component template.)

## Database Schema

```sql
CREATE TABLE essayist_zap_claim (
  id INT PRIMARY KEY,
  user_id INT NOT NULL,
  payer_pubkey VARCHAR(64) NOT NULL,
  sponsor_pubkey VARCHAR(64) NOT NULL,
  zap_receipt_event_id VARCHAR(64) UNIQUE NULL,
  bolt11_invoice VARCHAR(1024) UNIQUE NULL,
  claimed_amount_sats INT NULL,
  status VARCHAR(32) DEFAULT 'pending',
  rejection_reason TEXT NULL,
  verification_method VARCHAR(32) NULL,
  created_at TIMESTAMP NOT NULL,
  verified_at TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES "user"(id)
);
```

## Logging

Monitor claim activity in logs:

```bash
docker compose logs -f php | grep essayist_zap_claim
```

Key log entries:
- `essayist_zap_claim.created` — New claim submitted
- `essayist_zap_claim.auto_verified` — Receipt found and verified
- `essayist_zap_claim.approved` — Admin approved
- `essayist_zap_claim.rejected` — Admin rejected

## API Examples

### Submit a Claim (Auto-Verify)
```bash
curl -X POST http://localhost/api/essayist/claim-zap \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=..." \
  -d '{
    "zapReceiptEventId": "a1b2c3d4...",
    "sponsorNpub": "npub1...",
    "claimedAmountSats": 5000
  }'
```

**Response (201 auto-verified):**
```json
{
  "id": 1,
  "status": "verified",
  "verified": true,
  "message": "Zap claim verified and membership extended!"
}
```

### Submit a Claim (Pending Review)
```bash
curl -X POST http://localhost/api/essayist/claim-zap \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=..." \
  -d '{
    "bolt11Invoice": "lnbc5000...",
    "sponsorNpub": "npub1...",
    "claimedAmountSats": 5000
  }'
```

**Response (202 pending):**
```json
{
  "id": 2,
  "status": "pending",
  "verified": false,
  "message": "Zap claim submitted and pending verification. An admin will review it shortly."
}
```

### Get My Claims
```bash
curl http://localhost/api/essayist/my-claims \
  -H "Cookie: PHPSESSID=..."
```

**Response:**
```json
[
  {
    "id": 1,
    "status": "verified",
    "createdAt": "2026-06-03T12:34:56+00:00",
    "verifiedAt": "2026-06-03T12:34:57+00:00",
    "claimedAmountSats": 5000,
    "verificationMethod": "auto_receipt",
    "rejectionReason": null
  }
]
```

## Troubleshooting

### Claim not auto-verifying
- Check if zap receipt event is in local strfry: `docker compose logs -f strfry`
- Verify event ID is correct (64 hex chars, event kind 9735)
- Check if amount tag is present on receipt event

### Admin approval not working
- Verify admin role: `docker compose exec php bin/console security:check`
- Check MySQL dupicate key errors: `docker compose logs -f php | grep UNIQUE`
- Verify claim amount is >= `essayist.membership.minimum_sats` (~1000 sats)

### Component not rendering
- Check Live Component is registered: `bin/console debug:container | grep EssayistClaimZap`
- Verify Twig extension is auto-configured (SyncBundle)
- Check browser console for JS errors

## Future Enhancements

1. **Invoice verification via LNURL API** — Query issuer to check invoice status
2. **Webhook support** — Listen for `invoice.complete` events from Lightning services
3. **Bulk admin import** — CSV claim approval for batch processing
4. **Member notification** — Email/in-app alerts when claims are processed
5. **Claim expiration** — Auto-reject pending claims older than 30 days

## Support

- **Docs:** `documentation/Essayist/essayist-zap-claims.md`
- **Code Reference:** See inline comments in service/command classes
- **Admin Help:** `bin/console essayist:review-zap-claims --help`

