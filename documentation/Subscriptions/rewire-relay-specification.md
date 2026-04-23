# ReWire Relay Specification

## Overview

ReWire Relay is a Nostr relay with access control for publishing and gated content:

1. **Publisher Access Layer** - Controls who can publish content to the relay
2. **Scope Entitlement Layer** - Controls who can access specific gated content

The relay validates payments, issues grants, and enforces authorization on write operations and gated content access.

---

## Tag Indexing Requirement

Nostr relays by default only index single-letter tags (NIP-01). The `scope` tag used throughout this spec is multi-character and will **not** be indexed by stock relay builds.

ReWire Relay MUST be configured to index the `scope` tag as a queryable filter (`#scope`). With strfry this is done by extending the plugin/index configuration to include `scope`; other relay implementations need equivalent configuration. All filters shown in this document assume this configuration is active.

---

## Authorization Model

### Connection

| Content Type | Read | Write | Reason |
|--------------|------|-------|--------|
| **Open content** (articles, profiles) | AUTH required	 | Publisher grant | Public content |
| **Subscription events** (8101-8103, 8110, 8112-8113, 18101, 38110) | AUTH required | AUTH required | Privacy + flow completion |
| **Gated content** (with #scope) | AUTH + entitlement | Publisher grant | Paid content |


---

### Read Authorization (REQ)

**Requirements**:
- NIP-42 AUTH required across the board
- Active scope entitlement (if content is gated)

**Enforcement**:
- Open content (no `#scope` tag, not subscription events): freely accessible
- Subscription system events (kinds 8101-8103, 8110, 8112-8113, 18101, 38110):
  - Allow any authenticated user to read their own grants and requests
- Gated content (with `#scope` tag):
  - Query internal ledger for entitlement matching `(subscriber_pubkey, scope_coordinate)`
  - Verify not expired

---

### Write Authorization (EVENT)

**Requirements by Kind**:

| Kind                | Requirement                       | Notes                                      |
|---------------------|-----------------------------------|--------------------------------------------|
| 18101, 8102         | Relay operator only               | Publish grant, scope membership grant      |
| 8101, 8112          | Relay operator only               | Revoke events                              |
| 38110               | Publisher grant + ownership match | Scope definition                           |
| 8110                | AUTH only (no publisher grant)    | Subscribe request (any authenticated user) |
| 8103                | Publisher grant + scope ownership | Whitelist grant                            |
| 8113                | Publisher grant + scope ownership | Whitelist revoke                           |
| 30023, 30024, 30040 | Publisher grant                   | Long-form content, publications            |
| Other kinds         | TBD                               | Default policy                             |

**Ownership Verification**:
- For scope definitions: verify event author matches scope coordinate owner
- For whitelist grants: verify event author owns referenced scope

**Rejection Format**: `["OK", <event_id>, false, "restricted: <reason>"]`

---

## Payment Verification

### Zap Receipt Validation (kind 9735)

**Required Checks**:
1. Receipt signature valid (NIP-01)
2. `description` tag contains valid zap request (kind 9734)
3. Zap request signature valid
4. Recipient `p` tag matches expected recipient
5. Payer `P` tag matches zap request author
6. Amount `amount` tag ≥ minimum required
7. Preimage `preimage` tag exists (proof of payment)

**On Success**:
- Issue grant event

**On Failure**:
- Return error reason
- Do NOT issue grant

---

## Grant Issuance

### Publisher Grant (kind 18101)

**Trigger**: Payment verified for publisher access


**Event Structure**:
```json
{
  "kind": 18101,
  "pubkey": "<relay_operator_pubkey>",
  "tags": [
    ["p", "<publisher_pubkey>"],
    ["expiration", "<unix_timestamp>"],
    ["zap", "<receipt_event_id>"]
  ],
  "content": "Publisher grant issued until <date>"
}
```

---

### Scope Membership Grant (kind 8102)

**Trigger**: Valid `Subscribe Request` (kind 8110) received

**Validation Steps**:
1. Fetch scope definition from ledger
2. If scope minimum > 0 sats, verify payment evidence:
   - **If zap**: Verify receipt, check amount ≥ `min_sats`, verify payer = subscriber
   - **If coupon**: Verify whitelist grant exists and not expired, and that its single-use quota is not exhausted (see §Bearer Whitelist Quota)
   - Check receipt not already consumed (see §Receipt Replay Protection)
3. If scope minimum = 0 sats, grant without payment (free tier for analytics); apply anti-abuse rate limit (see §Free-Tier Rate Limit)

**Minimum in Effect**: The `min_sats` used for validation is the value present in the `Scope Definition` whose `created_at` is the latest value **not greater than** the subscribe request's `created_at`. This prevents a publisher racing a price hike against pending subscribe requests (and vice versa).

**Event Structure**:
```json
{
  "kind": 8102,
  "pubkey": "<relay_operator_pubkey>",
  "tags": [
    ["p", "<subscriber_pubkey>"],
    ["a", "<scope_def_coordinate>"],
    ["scope", "<scope_coordinate>"],
    ["expiration", "<unix_timestamp>"],
    ["e", "<subscribe_request_id>"]
    // Optional: ["zap", "<receipt_id>"] if paid
    // Optional: ["comped", "true"] if whitelisted
    // Optional: ["free", "true"] if scope is free tier
  ],
  "content": "Entitlement granted until <date>"
}
```

**Expiration Calculation**:
- Use `expires_in` from scope definition
- Default: 31536000 seconds (1 year)
- Calculate: `created_at + expires_in`

---

## Event Processing

### Scope Definitions (kind 38110)

**On Receipt**:
1. Verify author has publisher grant
2. Verify scope ownership matches author

**Note**: Parameterized replaceable, newer events replace older by npub + d-tag

---

### Subscribe Requests (kind 8110)

**On Receipt**:
1. Extract `a` (scope def), `scope`, evidence tags
2. Fetch scope definition
3. Validate payment evidence (see Grant Issuance above)
4. Issue `Membership Grant`

**Non-member Acceptance**: MAY accept from non-members (they get entitlement but still can't connect to relay without membership)

---

### Whitelist Grants (kind 8103)

**On Receipt**:
1. Verify author owns referenced scope

**Bearer Tokens**: If `p` tag absent, grant applies to anyone presenting it (until expiration), subject to the quota rules in §Bearer Whitelist Quota.

---

## Receipt Replay Protection

A single zap receipt (`kind 9735`) MUST NOT mint more than one `Membership Grant`.

**Mechanism**:
- The relay keeps an internal ledger keyed by `(zap_receipt_event_id)` of every receipt that has been consumed to mint a grant.
- When processing a subscribe request, the relay MUST reject it if any referenced `zap` tag value is already present in the ledger.
- The ledger key is the zap receipt event id; the bolt11 `payment_hash` / `preimage` MAY be indexed additionally for cross-checks but is not the primary key (a single invoice can be referenced by multiple receipts, but each receipt event id is unique by NIP-01).
- Ledger entries are retained indefinitely (a receipt is never "unused").

**Error**: `["OK", <event_id>, false, "restricted: zap receipt already consumed"]`.

---

## Bearer Whitelist Quota

A `Whitelist Grant` without a `["p"]` tag is a **bearer token** — any pubkey that references it in a subscribe request can claim the comped grant. Without a quota this is a DoS / free-lunch vector.

Bearer whitelist grants MUST carry one of:
- `["uses", "<n>"]` — maximum number of distinct pubkeys that can redeem this grant. The relay tracks a counter keyed by `(whitelist_grant_event_id)` and rejects requests when the counter reaches `n`.
- `["expiration", "<unix_seconds>"]` — hard deadline, after which no redemptions are accepted.

Relays MUST reject bearer whitelist grants that carry neither `uses` nor `expiration`. Scope owners SHOULD provide both.

Targeted whitelist grants (those with `["p", "<invitee_pubkey>"]`) are implicitly single-use per invitee and do not require `uses`.

---

## Free-Tier Rate Limit

Because 0-sat scopes have no payment barrier, a single pubkey farm can mint unlimited grants.

Relays MUST apply rate limits to free-tier membership grant issuance. Recommended defaults:
- No more than **1** grant per (subscriber pubkey, scope coordinate) per 24 h.
- No more than **N** grants minted per subscriber pubkey per hour across all scopes (operator-configured; default `20`).
- Scope owners MAY additionally specify `["rate_limit", "<grants_per_hour>"]` on the scope def as a per-scope cap.

Rate-limited requests are rejected with `["OK", <event_id>, false, "restricted: rate limit exceeded"]`.

---

## Scope Definition Version Conflicts

`Scope Definition` is parameterized-replaceable: newer events by the same `(pubkey, d)` replace older ones.

Entitlements already granted are **not** affected by later scope-def changes: the grant's `expiration` stands.

For **pending** subscribe requests (those that arrive while a scope-def change is in flight), the relay MUST validate against the scope def whose `created_at` is the latest value **not greater than** the subscribe request's `created_at`. Publishers cannot raise the price retroactively on payments already in flight; subscribers cannot replay an old low-price request against a higher-priced current def.

---

## Relay Signer Key Management

The relay signs `kind 18101`, `8101`, `8102`, and `8112` events with an operator-controlled keypair (the "issuer key").

**Storage**: The issuer private key MUST be stored in a secret backend (Symfony vault, Docker secret, or env var from a mounted secret file). It MUST NOT be committed to git or baked into container images.

**Rotation**: Operators SHOULD rotate the issuer key at least annually. Rotation procedure:
1. Generate a new keypair and publish a `kind 10002` (relay list) or operator announcement pinning the new issuer pubkey.
2. Update the relay config; new grants are signed with the new key.
3. Existing grants signed with the retired key remain valid until their `expiration`.
4. The retired public key MUST remain in the client's trusted-issuer set until all grants it signed have expired.

**Compromise**: On suspected compromise, the operator MUST:
1. Immediately rotate the issuer key (as above).
2. Publish `kind 8101` / `8112` revoke events **from the new key** for any grants believed to have been fraudulently issued, referencing the suspect grant event ids.
3. Announce the compromise window so clients can invalidate cached grants created during it.

**Client verification**: DN Client MUST verify the `pubkey` of every `18101`/`8102` event against the configured trusted-issuer set before honoring it.
