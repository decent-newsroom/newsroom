# Essayist zap claims

Members and candidates can claim a membership contribution from the members directory. The claim service supports invoice/preimage proof, locally stored zap receipts, recipient confirmation, and administrative review.

## Claim flow

1. On `/essayist/members`, supply the recipient's npub and a receipt event ID or BOLT11 invoice. A payment preimage must be accompanied by its invoice.
2. `EssayistZapClaimService::verifyClaim()` tries invoice/preimage verification first, then looks up a kind 9735 receipt in the local PostgreSQL `event` table. It does not fetch missing receipts from relays.
3. Invoice-only claims and unavailable receipts remain pending. Invalid preimage proofs or receipts without a usable amount are rejected.
4. The recipient can confirm a pending claim through `POST /essayist/claims/{id}/attest`. This action checks CSRF and requires the logged-in recipient's pubkey to match the claim's sponsor.
5. An operator can approve or reject pending claims with the CLI below.

The members-page zap dialog can capture a pending invoice claim automatically. The payer can discard a pending claim through the claim component. Generating an invoice alone does not prove payment.

## Verification and grants

| Method | Evidence and behavior |
|---|---|
| `preimage` | Verifies the preimage against the BOLT11 payment hash; uses the invoice amount, falling back to the claimed amount when absent. |
| `auto_receipt` | Finds a stored kind 9735 event by ID; reads its `amount` tag in millisats and uses its creation timestamp. |
| `recipient_attestation` | The logged-in sponsor confirms the amount; an optional kind 9741 event ID and note are stored as attestation metadata. |
| `manual` | An operator approves a positive amount after reviewing the claim. |

All successful paths call `EssayistMembershipService::recordGrant()`, which applies the configured minimum contribution and calendar-month membership rule. See [Essayist membership](essayist.md).

Receipt IDs and invoices have unique claim constraints. Repeating an approval of a non-pending claim does not create another grant. Preimage, recipient, and admin paths use deterministic identifiers for their own grant path; these identifiers do not universally deduplicate a later real receipt for the same payment.

The current receipt path reads the receipt amount and timestamp; it does not independently compare the receipt's payer/recipient tags with the claim. Documentation must not describe the local lookup or uniqueness constraints as complete receipt authenticity verification.

## API

Both endpoints require an authenticated application user.

- `POST /api/essayist/claim-zap`: accepts `sponsorNpub`, optional `zapReceiptEventId`, `bolt11Invoice`, `paymentPreimage`, and `claimedAmountSats`. At least one proof field is required.
- `GET /api/essayist/my-claims`: returns the current user's history, status, timestamps, verification method, amount, and rejection reason.

Submission returns `201` when verification succeeds and `202` otherwise. Read the returned `status`: the current API can return `202` for a rejected proof too. A receipt already attached to another claim returns `409`. Invoice uniqueness is enforced by persistence; the API does not currently translate every uniqueness failure into `409`.

## Operator review

~~~bash
docker compose exec php bin/console essayist:review-zap-claims
docker compose exec php bin/console essayist:review-zap-claims 5
docker compose exec php bin/console essayist:review-zap-claims 5 --amount=5000
docker compose exec php bin/console essayist:review-zap-claims 5 --reject="Payment could not be confirmed"
~~~

The command lists pending claims or processes one claim interactively. Verify payment evidence before approval; a valid invoice string alone is insufficient. Apply the normal Doctrine migrations during deployment.

## Implementation

- [Claim entity](../../src/Entity/EssayistZapClaim.php) and [repository](../../src/Repository/EssayistZapClaimRepository.php)
- [Verification service](../../src/Service/Essayist/EssayistZapClaimService.php)
- [JSON API](../../src/Controller/Api/Essayist/EssayistZapClaimController.php)
- [Claim component](../../src/Twig/Components/Molecules/EssayistClaimZapButton.php)
- [Review command](../../src/Command/EssayistReviewZapClaimsCommand.php)
