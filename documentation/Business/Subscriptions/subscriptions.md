# Subscriptions

Subscriptions are a way to receive access to gated content. 


## 1. Goals for ReWire Relay and Scope Subscriptions

1. Provide a **publisher access layer** where the relay sets price and issues publisher grants (write access).
2. Provide a **scope subscription layer** where scope owners publish subscription terms and subscribers pay scope owners; ReWire Relay mints entitlements.
3. Provide **subscribers export** via on-demand snapshots to the scope owner.

## 2. Terminology

* **Guest:** user browsing content without authentication.
* **Subscriber:** user with active scope entitlement (paid content creator).
* **Publisher:** user with active ReWire Relay publish grant (write access).
* **Scope:** one of `{npub, publication, article}`.
* **Scope Owner:** pubkey that controls the scope definition.
* **Entitlement:** a relay-issued subscription grant for a scope.

## 3. Canonical Scope (`scope` tag)

All scope-related events MUST carry `["scope", "<coordinate>"]` where `<coordinate>` is one of:

* **npub scope**: `<hex_pubkey>` (bare pubkey)
* **publication scope**: `30040:<hex_pubkey>:<dtag>`
* **article scope**: `30023:<hex_pubkey>:<dtag>`
  
`scope` MUST be treated as the primary key for indexing, entitlement checks, and exports.

**Normative Constraint**: In all events that reference a `DN_SCOPE_DEF` via `["a", "38110:..."]`, the `["scope", "..."]` value **MUST** equal the `scope` value declared in that `DN_SCOPE_DEF`. This prevents scope-swapping attacks and ensures deterministic entitlement lookups.

**Nested Indices**: For nested publication indices (30040 → 30040 → 30023), the scope coordinate references the **root 30040**. Relays grant access to all contained resources by traversing the index structure via `["a", ...]` tags in the 30040 events.

## 4. Kind allocation

### 4.1 Publisher access

* `DN_RELAY_PUBLISH_GRANT` (kind 18101) - relay grants publisher write access
* `DN_RELAY_PUBLISH_REVOKE` (kind 8101) - relay revokes publisher access

### 4.2 Scope subscriptions

* `DN_SCOPE_DEF` (kind 38110) - scope owner defines scope + minimum + preview
* `DN_SCOPE_SUBSCRIBE_REQUEST` (kind 8110) - subscriber references receipts and asks for entitlement
* `DN_SCOPE_MEMBERSHIP_GRANT` (kind 8102) - relay issues entitlement to subscriber
* `DN_SCOPE_MEMBERSHIP_REVOKE` (kind 8112) - relay revokes entitlement
* `DN_SCOPE_WHITELIST_GRANT` (kind 8103) - scope owner whitelists subscriber without payment
* `DN_SCOPE_WHITELIST_REVOKE` (kind 8113) - scope owner removes whitelist

### 4.3 Exports (portable)

* NIP-51 `kind:30000` “categorized people list” is used for subscriber exports.

---

# Part A: ReWire Relay Specification

## A1. Overview

This section specifies the relay-side implementation for managing publisher grants, scope subscriptions, and entitlements.

## A2. High-level policy

### A2.1 Open reads and writes

The ReWire Relay provides **open read access** for all content. This aligns with Nostr principles and ensures maximum compatibility with standard Nostr clients.

Policy:

* Anyone MAY connect to the relay with NIP-42 AUTH
* Open content (without scope tags) is freely accessible to all
* Gated content (with scope tags) requires:
    * Active scope entitlement (`DN_SCOPE_MEMBERSHIP_GRANT`)
* Subscription request (kind `8110`) events are allowed to be written without the publisher grant, to enable user subscription flows.

### A2.2 Publisher-only writes

Write access is restricted to publishers who have paid for publishing rights.

Requirements for write access:

* Pubkey that signed the event has an active **publisher grant**


## A3. Publisher Access

### A3.1 Publisher grant

Authors pay for publishing rights. The relay requires a **publisher grant** for writes that create/modify subscription scopes and for publishing content.

When payment is verified, the relay issues `DN_RELAY_PUBLISH_GRANT` (relay-signed):

Required tags:

* `["p", "<publisher_pubkey>"]`
* `["expiration", "<unix_seconds>"]` (MUST)
* Optional payment audit:
    * `["zap", "<receipt_id>"]` and/or `["invoice", "<payment_hash>"]`


### A3.2 Write authorization

Relay write rules:

* Publishing `DN_SCOPE_DEF`, and any "publishable content kinds" (30023, 30024, 30040, 20, 21 etc.) that ReWire Relay hosts MUST require an active publisher grant.
* The relay MAY additionally support an admin whitelist for publisher status.

## A4. Scope Subscriptions

### A4.1 Scope definition (`DN_SCOPE_DEF`)

Authored and signed by the **scope owner**, this event specifies the subscription terms for a scope.

Required tags:

* `["scope", "<pubkey|coordinate>"]`
* Scope pointer (additional, traditional tags handy for indexing):

    * npub: `["p", "<owner_pubkey>"]`
    * publication/article: `["a", "<kind>:<pubkey>:<dtag>"]`

Pricing:

* `["subscription", "<min_sats>"]` OPTIONAL
  If missing, minimum defaults to **0 sats** (free tier - publisher collects subscriptions for stats/analytics, eventually notifs). 

Optional tags:

* `["expires_in", "<seconds>"]` (recommended policy duration for entitlements)

In addition, the scope def MAY include as standard Nostr tags the title, summary, and cover image for previewing.

Relay acceptance:

* Relay MUST require **publisher grant** for publishing/updating scope definitions on the ReWire Relay.
* Relay MUST validate that the author of the definition is consistent with the scope pointer (fail closed if inconsistent).

### A4.2 Subscribe request (`DN_SCOPE_SUBSCRIBE_REQUEST`)

Authored and signed by the **subscriber**. This provides a durable reference for the subscription action.

Required tags:

* Reference to the scope definition coordinate:
    * `["a", "<DN_SCOPE_DEF_coordinate>"]` (REQUIRED)
* One or more evidence tags (if payment required):
    * `["zap", "<zap_receipt_event_id>"]` (repeatable)
    * `["coupon", "<coordinate>"]` (scope owner may issue coupons that reference whitelist grants)
    * If scope minimum = 0 sats, no evidence tags required (free subscription)

Relay handling:

* The relay accepts subscribe requests from anyone and mints entitlement GRANTs if they pass requirements listed in the scope definition.
* For free scopes (min_sats = 0), relay SHOULD mint grant without payment verification to enable publisher analytics.

### A4.3 Entitlement minting (`DN_SCOPE_MEMBERSHIP_GRANT`)

Entitlements are membership-shaped and issued by the relay to the subscriber.

Event is relay-signed.

Required tags:

* `["p", "<subscriber_pubkey>"]`
* `["a", "<DN_SCOPE_DEF_coordinate>"]`
* `["expiration", "<unix_seconds>"]` (MUST)

Optional tags:

* `["zap", "<receipt_id>"]` (audit trail, if payment was made, repeatable)
* `["comped", "true"]` if issued via whitelist/invite rather than payment
* `["min_sats", "<value>"]` snapshot for audit
* `["free", "true"]` if scope is free tier (min_sats = 0)

Relay MUST only accept this kind from itself.

### A4.4 Whitelist/invite (scope-only bypass)

Scope owners can include people without payment, but only within their scope.

`DN_SCOPE_WHITELIST_GRANT` (owner-signed)

Required tags:

* `["a", "<coordinate>"]` (referencing `DN_SCOPE_DEF`)
* `["p", "<invitee_pubkey>"]` (OPTIONAL; if missing, applies to anyone who presents it while valid)
* `["expiration", "<unix_seconds>"]` (RECOMMENDED, especially if `p` is missing)

Relay enforcement:

* Relay MUST verify the grant author is the scope owner for the scope definition.
* Relay MUST treat whitelist grants as satisfying entitlement checks (subject to expiration).
* The relay SHOULD mint `DN_SCOPE_MEMBERSHIP_GRANT` with `comped=true`.

## A5. Payment evidence rules (zap receipts)

When minting a paid entitlement, the relay MUST verify at least:

1. The receipt is a valid zap receipt event.
2. The receipt binds payer to the subscriber pubkey (non-anonymous enforcement; fail closed otherwise).
3. The receipt references the scope definition and/or scoped resource strongly enough to avoid misbinding.
4. Paid amount ≥ minimum derived from `DN_SCOPE_DEF`:
    * If `["subscription", …]` missing, minimum = 0 sat (free tier).
    * If minimum = 0, relay MAY grant entitlement without payment receipt (free subscription for stats).

For free subscriptions (min_sats = 0), the relay SHOULD still mint `DN_SCOPE_MEMBERSHIP_GRANT` to track subscriber interest and provide analytics to the publisher.

Nostr events allow for zap splits. Those can be used to define multiple recipients per scope subscription payment. 
But splits require as many invoices as there are recipients, and the UX and error surface are more complex.
Split enforcement is out of scope for v0; but is planned for later, by allowing multiple receipts per subscribe request. 
This part is more strongly limited on the client UX part than on the relay side.

## A6. Read and write authorization logic

### A6.1 Writes

* Most writes to ReWire Relay require:
    * NIP-42 AUTH + active publisher grant
    
* Exception: `DN_SCOPE_SUBSCRIBE_REQUEST` (8110) may be submitted by any authenticated user:
    * NIP-42 AUTH required
    * No publisher grant required

* Entitlement issuance events require:
    * Authored by relay issuer key only (relay operator)

### A6.2 Reads

* AUTH is always required (NIP-42)
* Open content is freely accessible
* Subscription system events (kinds 8101-8103, 8110, 8112-8113, 18101, 38110) are readable for issuer and mentions.
* For gated content within a scope, the relay MUST require active entitlement for `scope` (from `DN_SCOPE_MEMBERSHIP_GRANT`)


---

# Part B: DN Client Specification (public onboarding + subscription UX)

## B1. Client roles

* **Guest UX:** browse open content and previews (no authentication needed).
* **Subscriber UX:** access gated content after subscribing to scopes.
* **Publisher UX:** pay for publish rights, publish scopes, manage subscribers.

## B2. Public read surface

The DN Client provides public access to:

* Browsing of open content (not requiring scope entitlements)
* Previews/teasers for gated scopes
* Discovery: search, topics, journals, latest, etc.

Data sources:

* ReWire Relay (open read access, AUTH required)
* Replicated events from public relays (ingested into local store)

## B3. Author onboarding (publisher grant required)

The DN Client MUST provide a publisher onboarding flow:

1. Prompt for login (identify pubkey, double-check existing grants). 
2. Purchase publisher access:

    * pay relay (invoice/zap)
3. Confirm publisher grant is active (query relay for feedback).
4. Enable publishing UI and scope definition UI.

## B4. Scope creation and preview

Publisher UX MUST allow:

* Creating `DN_SCOPE_DEF` for:
    * npub scope
    * publication scope
    * article scope
* Setting minimum:
    * `subscription` (min sats)
    * Default is 0 (free tier)
* Optionally generating a preview tags:
    * title
    * summary
    * cover image

The client SHOULD make explicit what free tier (0 sats) means: "Collect subscriptions for analytics, no payment required".

## B5. Subscription purchase flow

The DN Client supports a simple subscription flow:

1. User clicks "Subscribe" on a scope.
2. Client shows:
    * price (from `DN_SCOPE_DEF`)
    * what it unlocks
    * optional preview
3. Client constructs a zap payment to the scope owner, embedding references:
    * reference to `DN_SCOPE_DEF` coordinate 
4. After payment, client obtains zap receipt id(s).
5. Client publishes `DN_SCOPE_SUBSCRIBE_REQUEST` (subscriber-signed), including `scope` and receipt ids.

Entitlement usability:

* DN Client unlocks content immediately after successful subscription
* Content is also accessible from any Nostr client (they can connect to ReWire Relay directly)
* DN Client provides benchmark UX for reading gated content, and
* DN Client provides subscriptions management UI.

## B6. Direct relay usage

Since ReWire Relay has open read access (using NIP-42 AUTH), users can:

* Add ReWire Relay to their relay list in any Nostr client
* Read open articles from any client
* Read gated articles if they have scope entitlements
* Publish content if they have publisher grants (but most clients won't support publishing to exactly one selected relay)

## B7. Owner subscriber management and exports

### B7.1 No continuous updates required

Owner does NOT update lists continuously. DN Client derives subscriber sets from:

* entitlement grants (paid and comped)
* expiration rules

### B7.2 “Pending/new since last export”

DN Client SHOULD provide:

* “New subscribers since last export”
* filters: paid vs comped, active vs expired, time range


### B7.3 Portable export artifact (recommended)

DN Client SHOULD generate an owner-signed NIP-51 `kind:30000` list:

* `kind: 30000`
* `pubkey: <scope_owner_pubkey>`
* `["d", "dn-subscribers-<scope_coordinate>"]`
* repeated `["p", "<subscriber_pubkey>"]`

The owner signs the NIP-51 event via their signer/extension. 
There should probably be some privacy considerations around exporting subscriber lists and sharing them indiscriminately.

## B8. Scope whitelist/invite management

DN Client MUST allow scope owners to:

* add a pubkey to scope whitelist (bypass payment)
* set expiry
* revoke

Note: Whitelist applies only to the referenced scope (not publisher or other scopes).
