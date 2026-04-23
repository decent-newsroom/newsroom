# Subscription Events Catalog

This document provides a complete reference of all Nostr events used in the ReWire Relay subscription system, with assigned kind numbers and detailed JSON examples.

## Table of Contents

1. [Kind Number Allocation](#kind-number-allocation)
2. [Publisher Access Events](#publisher-access-events)
3. [Scope Subscription Events](#scope-subscription-events)
4. [Export Events](#export-events)
5. [Supporting Events](#supporting-events)
6. [Event Relationship Diagram](#event-relationship-diagram)
7. [Quick Reference: Event Queries](#quick-reference-event-queries)

---

## Kind Number Allocation


### Assigned Kinds for Subscription System

**Note**: Events are organized by functional area below, but kind numbers follow Nostr conventions:
- **8xxx range** = Regular events (audit trail, not replaceable)
- **18xxx range** = Replaceable events (one per pubkey)
- **38xxx range** = Parameterized replaceable events (one per `d` tag)


| Kind | Event Type | Category | Replaceability | AUTH Required    |
|------|------------|----------|----------------|------------------|
| `18101` | `Publish Grant` | Publisher Access | Replaceable | Yes (read/write) |
| `8101` | `Publish Revoke` | Publisher Access | Regular | Yes (read/write) |
| `38110` | `Scope Definition` | Scope Subscriptions | Parameterized Replaceable | Yes (read/write) |
| `8110` | `Subscribe Request` | Scope Subscriptions | Regular | Yes (read/write) |
| `8102` | `Membership Grant` | Scope Subscriptions | Regular | Yes (read/write) |
| `8112` | `Membership Revoke` | Scope Subscriptions | Regular | Yes (read/write) |
| `8103` | `Whitelist Grant` | Scope Subscriptions | Regular | Yes (read/write) |
| `8113` | `Whitelist Revoke` | Scope Subscriptions | Regular | Yes (read/write) |
| `30000` | Subscriber Export List | Exports | Parameterized Replaceable (NIP-51) | Yes (owner only) |
| `9735` | Zap Receipt | Payment Evidence | Regular (NIP-57) | --               |

**Note**: While `Publish Grant` is replaceable (so it can be extended), `Membership Grant` (8102) is regular (not replaceable) because users can subscribe to multiple scopes (different publishers, publications, articles). Each subscription creates a separate grant event.

---

## Publisher Access Events

### Publish Grant (kind 18101)

**Purpose**: Relay grants publisher write access after payment.

**Authored by**: Relay operator key

**Replaceability**: Replaceable per pubkey

**Required Tags**:
- `["p", "<publisher_pubkey>"]` - who receives publisher access
- `["expiration", "<unix_seconds>"]` - when grant expires

**Optional Tags**:
- `["zap", "<receipt_event_id>"]` - audit trail to payment
- `["invoice", "<payment_hash>"]` - alternative payment reference
- `["relay", "<relay_url>"]` - which relay this applies to

**Example**:
```json
{
  "id": "d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5",
  "pubkey": "relay_operator_pubkey_32bytes_hex_encoded_public_key_here",
  "created_at": 1704326700,
  "kind": 18101,
  "tags": [
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["expiration", "1735862400"],
    ["zap", "9735_receipt_event_id_here_for_publisher_payment"],
    ["relay", "wss://relay.rewire.example"],
  ],
  "content": "Publisher grant issued until 2026-01-03",
  "sig": "4d5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7"
}
```

---

### Publish Revoke (kind 8101)

**Purpose**: Relay revokes publisher access.

**Authored by**: Relay operator key

**Replaceability**: Regular (creates audit trail)

**Required Tags**:
- `["p", "<publisher_pubkey>"]` - whose publish grant is revoked
- `["e", "<grant_event_id>"]` - references the original grant

**Optional Tags**:
- `["reason", "<text>"]` - why publisher access was revoked
- `["relay", "<relay_url>"]` - which relay this applies to

**Example**:
```json
{
  "id": "e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6",
  "pubkey": "relay_operator_pubkey_32bytes_hex_encoded_public_key_here",
  "created_at": 1704326800,
  "kind": 8101,
  "tags": [
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["e", "d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5"],
    ["reason", "Expired - no renewal payment"],
    ["relay", "wss://relay.rewire.example"]
  ],
  "content": "Publisher grant revoked",
  "sig": "5e6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8"
}
```

---

## Scope Subscription Events

### Scope Definition (kind 38110)

**Purpose**: Scope owner defines subscription terms for a scope.

**Authored by**: Scope owner

**Replaceability**: Parameterized replaceable (by `d` tag)

**Required Tags**:
- `["d", "<scope_identifier>"]` - unique identifier for this scope
- `["scope", "<coordinate>"]` - canonical scope coordinate (`pubkey` or `kind:pubkey:dtag`)
- Scope pointer (one of):
  - `["p", "<owner_pubkey>"]` - for npub scope
  - `["a", "<kind>:<pubkey>:<dtag>"]` - for publication/article scope

**Optional Tags**:
- `["subscription", "<min_sats>"]` - minimum payment (can be 0 or omitted for free content)
- `["expires_in", "<seconds>"]` - entitlement duration (recommended, i.e. one month = 2592000)
- `["title", "<title>"]` - scope title for preview
- `["summary", "<summary>"]` - scope description
- `["image", "<url>"]` - cover image URL
- `["published_at", "<unix_seconds>"]` - when scope was created

**Example (npub scope)**:
```json
{
  "id": "f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704326900,
  "kind": 38110,
  "tags": [
    ["d", "npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["subscription", "50000"],
    ["expires_in", "31536000"],
    ["title", "Premium Content Subscription"],
    ["summary", "Access to all my premium articles, podcasts, and exclusive content"],
    ["image", "https://example.com/premium-cover.jpg"],
    ["published_at", "1704326900"]
  ],
  "content": "Subscribe to get unlimited access to all my premium content. Includes weekly articles, monthly podcasts, and community discussions.",
  "sig": "6f7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9"
}
```

**Example (publication scope)**:
```json
{
  "id": "g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704327000,
  "kind": 38110,
  "tags": [
    ["d", "pub-tech-weekly"],
    ["scope", "30040:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:tech-weekly"],
    ["a", "30040:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:tech-weekly"],
    ["subscription", "100000"],
    ["expires_in", "31536000"],
    ["title", "Tech Weekly Magazine"],
    ["summary", "Deep dive technical analysis and tutorials every week"],
    ["image", "https://example.com/tech-weekly-cover.jpg"]
  ],
  "content": "Weekly publication covering the latest in Bitcoin, Lightning, and Nostr development.",
  "sig": "7g8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0"
}
```

**Example (article scope)**:
```json
{
  "id": "h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704327100,
  "kind": 38110,
  "tags": [
    ["d", "article-lightning-guide"],
    ["scope", "30023:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:lightning-ultimate-guide"],
    ["a", "30023:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:lightning-ultimate-guide"],
    ["subscription", "10000"],
    ["expires_in", "31536000"],
    ["title", "The Ultimate Lightning Network Guide"],
    ["summary", "Comprehensive 50-page guide to understanding and using Lightning"],
    ["image", "https://example.com/lightning-guide-cover.jpg"]
  ],
  "content": "A complete guide to Lightning Network from basics to advanced routing and channel management.",
  "sig": "8h9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1"
}
```

---

### Subscribe Request (kind 8110)

**Purpose**: Subscriber requests entitlement to a scope, providing payment evidence.

**Authored by**: Subscriber

**Replaceability**: Regular (creates audit trail, multiple subscriptions possible)

**Required Tags**:
- `["a", "<Scope Definition_coordinate>"]` - references the scope definition
- `["scope", "<coordinate>"]` - canonical scope coordinate
- One or more evidence tags:
  - `["zap", "<receipt_event_id>"]` - payment receipt (repeatable)
  - OR `["coupon", "<whitelist_grant_coordinate>"]` - whitelist grant reference

**Optional Tags**:
- `["p", "<scope_owner_pubkey>"]` - scope owner (for indexing)

**Example (paid subscription)**:
```json
{
  "id": "i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0",
  "pubkey": "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner",
  "created_at": 1704327200,
  "kind": 8110,
  "tags": [
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["zap", "zap_receipt_event_id_from_payment_50000_sats"],
    ["amount", "50000000"]
  ],
  "content": "Subscribing to Premium Content Subscription",
  "sig": "9i0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2"
}
```

**Example (whitelist/comped subscription)**:
```json
{
  "id": "j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1",
  "pubkey": "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner",
  "created_at": 1704327300,
  "kind": 8110,
  "tags": [
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["coupon", "<event-id-of-whitelist-grant-kind-8103>"]
  ],
  "content": "Subscribing with press pass coupon",
  "sig": "0j1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3"
}
```

**Example (free subscription - 0 sats)**:
```json
{
  "id": "k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2",
  "pubkey": "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner",
  "created_at": 1704327400,
  "kind": 8110,
  "tags": [
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:newsletter-free"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"]
  ],
  "content": "Subscribing to free newsletter",
  "sig": "1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4"
}
```

---

### Membership Grant (kind 8102)

**Purpose**: Relay mints entitlement after validating subscribe request and payment.

**Authored by**: Relay operator key

**Replaceability**: Regular (not replaceable) - users can have multiple scope subscriptions

**Required Tags**:
- `["p", "<subscriber_pubkey>"]` - who receives the entitlement
- `["a", "<Scope Definition_coordinate>"]` - which scope definition
- `["scope", "<coordinate>"]` - canonical scope coordinate
- `["expiration", "<unix_seconds>"]` - when entitlement expires

**Optional Tags**:
- `["zap", "<receipt_event_id>"]` - audit trail (repeatable for multiple receipts)
- `["e", "<subscribe_request_id>"]` - references the subscribe request
- `["comped", "true"]` - indicates whitelist/free access
- `["min_sats", "<value>"]` - snapshot of minimum at time of grant
- `["amount_paid", "<millisats>"]` - actual amount paid

**Example (paid entitlement)**:
```json
{
  "id": "k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2",
  "pubkey": "relay_operator_pubkey_32bytes_hex_encoded_public_key_here",
  "created_at": 1704327400,
  "kind": 8102,
  "tags": [
    ["p", "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner"],
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["expiration", "1735863400"],
    ["zap", "zap_receipt_event_id_from_payment_50000_sats"],
    ["e", "i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0"],
    ["min_sats", "50000"],
    ["amount_paid", "50000000"]
  ],
  "content": "Entitlement granted until 2026-01-03",
  "sig": "1k2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4"
}
```

**Example (comped entitlement)**:
```json
{
  "id": "l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3",
  "pubkey": "relay_operator_pubkey_32bytes_hex_encoded_public_key_here",
  "created_at": 1704327500,
  "kind": 8102,
  "tags": [
    ["p", "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner"],
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["expiration", "1735863400"],
    ["e", "j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1"],
    ["comped", "true"],
    ["min_sats", "50000"]
  ],
  "content": "Complimentary entitlement granted until 2026-01-03",
  "sig": "2l3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5"
}
```

**Example (free tier subscription - 0 sats)**:
```json
{
  "id": "m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4",
  "pubkey": "relay_operator_pubkey_32bytes_hex_encoded_public_key_here",
  "created_at": 1704327600,
  "kind": 8102,
  "tags": [
    ["p", "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner"],
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:newsletter-free"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["expiration", "1735863400"],
    ["e", "k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2"],
    ["free", "true"],
    ["min_sats", "0"]
  ],
  "content": "Free tier subscription granted until 2026-01-03 (analytics tracking)",
  "sig": "3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6"
}
```

---

### Membership Revoke (kind 8112)

**Purpose**: Relay revokes scope entitlement.

**Authored by**: Relay operator key

**Replaceability**: Regular (creates audit trail)

**Required Tags**:
- `["p", "<subscriber_pubkey>"]` - whose entitlement is revoked
- `["e", "<grant_event_id>"]` - references the original grant
- `["scope", "<coordinate>"]` - which scope

**Optional Tags**:
- `["reason", "<text>"]` - why entitlement was revoked
- `["a", "<Scope Definition_coordinate>"]` - scope definition reference

**Example**:
```json
{
  "id": "m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4",
  "pubkey": "relay_operator_pubkey_32bytes_hex_encoded_public_key_here",
  "created_at": 1704327600,
  "kind": 8112,
  "tags": [
    ["p", "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner"],
    ["e", "k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["reason", "Refund requested by subscriber"]
  ],
  "content": "Entitlement revoked",
  "sig": "3m4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6"
}
```

---

### Whitelist Grant (kind 8103)

**Purpose**: Scope owner grants complimentary access without payment.

**Authored by**: Scope owner

**Replaceability**: Regular (not replaceable)

**Required Tags**:
- `["a", "<Scope Definition_coordinate>"]` - which scope definition
- `["scope", "<coordinate>"]` - canonical scope coordinate

**Optional Tags**:
- `["p", "<invitee_pubkey>"]` - specific person (if missing, bearer token)
- `["expiration", "<unix_seconds>"]` - when whitelist expires (RECOMMENDED)
- `["title", "<text>"]` - title (e.g., "Press Pass 2026", "Beta Tester")

**Example (specific person)**:
```json
{
  "id": "n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704327700,
  "kind": 8103,
  "tags": [
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["p", "journalist_pubkey_32bytes_hex_encoded_here"],
    ["expiration", "1735862400"],
    ["title", "Press Pass 2026"]
  ],
  "content": "Complimentary access for press coverage",
  "sig": "4n5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7"
}
```

**Example (bearer/anyone - use with caution)**:
```json
{
  "id": "o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704327800,
  "kind": 8103,
  "tags": [
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["expiration", "1704931200"],
    ["label", "Beta Tester - First 100"]
  ],
  "content": "Coupon code for beta testers. Limited time offer.",
  "sig": "5o6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7y8"
}
```

---

### Whitelist Revoke (kind 8113)

**Purpose**: Scope owner revokes a whitelist grant.

**Authored by**: Scope owner

**Replaceability**: Regular (creates audit trail)

**Required Tags**:
- `["e", "<whitelist_grant_event_id>"]` - references the whitelist grant
- `["scope", "<coordinate>"]` - which scope

**Optional Tags**:
- `["p", "<invitee_pubkey>"]` - if revoking specific person's access
- `["reason", "<text>"]` - why whitelist was revoked

**Example**:
```json
{
  "id": "p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704327900,
  "kind": 8113,
  "tags": [
    ["e", "n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["p", "journalist_pubkey_32bytes_hex_encoded_here"],
    ["reason", "Press coverage completed"]
  ],
  "content": "Whitelist access revoked",
  "sig": "6p7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7y8z9"
}
```

---

## Export Events

### Subscriber Export List (kind 30000)

**Purpose**: Portable list of subscribers for a scope (NIP-51 categorized people list).

**Authored by**: Scope owner

**Replaceability**: Parameterized replaceable (by `d` tag)

**Standard**: NIP-51

**Required Tags**:
- `["d", "<list_identifier>"]` - typically some auto-generated suggestion, e.g., "dn-subscribers-npub-premium-2026-01"
- Multiple `["p", "<subscriber_pubkey>", "<relay_hint>", "<petname>"]` - list of subscribers

**Optional Tags**:
- `["scope", "<coordinate>"]` - which scope this export is for
- `["title", "<title>"]` - list title
- `["description", "<text>"]` - list description
- `["exported_at", "<unix_seconds>"]` - when this export was generated
- `["filter", "<criteria>"]` - what filter was applied (e.g., "active-paid-only")

**Example**:
```json
{
  "id": "q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7v8",
  "pubkey": "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d",
  "created_at": 1704328000,
  "kind": 30000,
  "tags": [
    ["d", "dn-subscribers-npub-premium-2026-01"],
    ["scope", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["title", "Premium Subscribers - January 2026"],
    ["description", "Active paid subscribers to my premium content"],
    ["exported_at", "1704328000"],
    ["filter", "active-paid-only"],
    ["p", "subscriber1_pubkey_32bytes_here", "wss://relay.rewire.example", "Alice"],
    ["p", "subscriber2_pubkey_32bytes_here", "wss://relay.rewire.example", "Bob"],
    ["p", "subscriber3_pubkey_32bytes_here", "wss://relay.rewire.example", "Carol"],
    ["p", "subscriber4_pubkey_32bytes_here", "wss://relay.rewire.example", ""],
    ["p", "subscriber5_pubkey_32bytes_here", "wss://relay.rewire.example", ""]
  ],
  "content": "Subscriber export for Premium Content Subscription as of January 2026. Total: 5 active subscribers.",
  "sig": "7q8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7y8z9a0"
}
```

---

## Supporting Events

### Zap Receipt (kind 9735)

**Purpose**: Lightning payment receipt (defined by NIP-57).

**Authored by**: Lightning service provider / LNURL server

**Replaceability**: Regular

**Standard**: NIP-57

**Required Tags** (per NIP-57):
- `["p", "<recipient_pubkey>"]` - payment recipient
- `["P", "<sender_pubkey>"]` - payment sender (from zap request)
- `["bolt11", "<invoice>"]` - the Lightning invoice that was paid
- `["description", "<zap_request_json>"]` - the original zap request (kind 9734) as JSON string
- `["preimage", "<preimage>"]` - payment preimage (proof of payment)

**Optional Tags**:
- `["e", "<event_id>"]` - if zapping a specific event
- `["a", "<coordinate>"]` - if zapping a parameterized replaceable event (like scope def)
- `["amount", "<millisats>"]` - amount paid in millisatoshis

**Example (subscription payment)**:
```json
{
  "id": "r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2g3h4i5j6k7l8m9n0o1p2q3r4s5t6u7v8w9",
  "pubkey": "lnurl_service_pubkey_32bytes_hex_encoded_here",
  "created_at": 1704327250,
  "kind": 9735,
  "tags": [
    ["p", "3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d"],
    ["P", "subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner"],
    ["bolt11", "lnbc500u1p3xnhl2pp5awzvjr8ehpdfgcjl8kq0rxuqmh9ejnk9yxz2kzd0s7vnp6j7s2fqdq2w3jhxaqcqzpgxqyz5vqsp5vzlgzujhwh5w43gw0hxlj4k5f7y4c5j4w8hjh0rvx73p8hwpwzks9qyyssqd7jhh9m0kj0dqv8l5xf7h5ghz7j8qxzm9c0d3p5k2n4r6s8tgajhdxq0jv7l8p2q4r6s8tavhwe"],
    ["description", "{\"kind\":9734,\"content\":\"Subscribing to Premium Content\",\"tags\":[[\"p\",\"3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d\"],[\"a\",\"38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium\"],[\"amount\",\"50000000\"],[\"relays\",\"wss://relay.rewire.example\"]],\"pubkey\":\"subscriber_pubkey_32bytes_hex_encoded_here_different_from_owner\",\"created_at\":1704327200,\"id\":\"zap_request_event_id\",\"sig\":\"zap_request_sig\"}"],
    ["preimage", "f7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8"],
    ["amount", "50000000"],
    ["a", "38110:3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d:npub-premium"]
  ],
  "content": "Payment received: 50000 sats for Premium Content Subscription",
  "sig": "8r9s0t1u2v3w4x5y6z7a8b9c0d1e2f3g4h5i6j7k8l9m0n1o2p3q4r5s6t7u8v9w0x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0r1s2t3u4v5w6x7y8z9a0b1"
}
```
