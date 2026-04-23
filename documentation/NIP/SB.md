NIP-SB
======

Relay-Enforced Scoped Entitlements
----------------------------------

`draft` `optional` `relay` `client`

## Abstract

This NIP defines a relay-enforced entitlement system for **scoped access** to Nostr content.

A scope owner publishes a **Scope Definition** describing a gated scope and its access terms. A subscriber presents payment evidence in an **Entitlement Request**. A relay verifies that evidence and issues a signed **Entitlement Grant**. Entitlements can later be terminated with an **Entitlement Revoke**. Scope owners may also export active subscribers as a portable [NIP-51](51.md) `kind:30000` list.

In this NIP, payment is made **directly to the scope owner or the scope owner’s chosen payment processor**. The relay is not the seller of access. The relay only verifies payment evidence and enforces scoped entitlement locally.

## Motivation

Nostr already has pieces of the paid-access stack, but they live at different layers.

Relay metadata can advertise coarse relay capabilities and policies. Relay authentication already exists. Zap receipts already prove that a payment occurred. Lists already provide a portable way to publish sets of pubkeys. But none of those define the missing primitive:

> this authenticated pubkey currently has access to this exact author, publication, or article scope on this relay.

This NIP defines that primitive.

It is intentionally narrow:

* it does **not** define relay-wide paid access,
* it does **not** define a checkout or storefront protocol,
* it does **not** make relays the merchant,
* it does **not** try to encode scoped entitlement state into relay metadata.

## Scope

This NIP standardizes:

* scope definitions,
* entitlement requests,
* relay-issued grants and revokes,
* entitlement state semantics,
* relay-side read enforcement,
* portable subscriber exports.

This NIP does **not** standardize:

* how a relay charges for relay access,
* how a creator designs payment plans,
* how a payment processor works internally,
* inheritance of scope across replies, media, or child events,
* confidentiality of subscription facts.

## Terminology

* **Scope** — a gated resource identifier.
* **Scope owner** — the pubkey that controls a scope and may publish its Scope Definition.
* **Subscriber** — a pubkey requesting or holding entitlement to a scope.
* **Issuer key** — the relay-operator keypair that signs relay-authored entitlement events.
* **Entitled content** — content carrying a scope tag defined by this NIP.
* **Open content** — content without a scope tag defined by this NIP.
* **Payment recipient** — the pubkey that must receive payment for the scope. By default this is the scope owner, but it may be a designated processor or gateway chosen by the scope owner.

## Event Kinds

| Kind    | Event               | Signer        | Replaceability                  |
| ------- | ------------------- | ------------- | ------------------------------- |
| `38110` | Scope Definition    | Scope owner   | Parameterized                   |
| `8110`  | Entitlement Request | Subscriber    | Regular                         |
| `8102`  | Entitlement Grant   | Issuer        | Regular                         |
| `8112`  | Entitlement Revoke  | Issuer        | Regular                         |
| `30000` | Subscriber Export   | Scope owner   | Parameterized ([NIP-51](51.md)) |
| `9735`  | Zap receipt         | LNURL service | Regular ([NIP-57](57.md))       |

`38110` is parameterized-replaceable so scope terms can evolve over time.
`8110`, `8102`, and `8112` are regular so requests and entitlement transitions form an append-only audit trail.

## Canonical Scope Reference

All entitlement-aware events and all entitled content MUST carry exactly one:

* `["G", "<scope_ref>"]`

`<scope_ref>` is one of:

* `p:<hex_pubkey>` — author scope
* `a:<kind>:<hex_pubkey>:<dtag>` — addressable scope; `<kind>` MUST be in the parameterized-replaceable range (`30000`–`39999`) so the scope reference resolves to a single addressable event

Examples:

* `["G", "p:<author_pubkey>"]`
* `["G", "a:30023:<author_pubkey>:essay-42"]`
* `["G", "a:30040:<owner_pubkey>:magazine-premium"]`

The `G` tag is the authoritative scope key for this NIP.

## Scope Ownership

The scope owner is derived from the scope reference:

* for `p:<pubkey>`, the owner is `<pubkey>`
* for `a:<kind>:<pubkey>:<dtag>`, the owner is the middle `<pubkey>`

Only the scope owner may author the corresponding Scope Definition.

## Scope Definition (kind `38110`)

A Scope Definition advertises access terms for one scope.

### Required tags

* `["d", "<scope_identifier>"]`
* `["G", "<scope_ref>"]`
* one scope pointer:

    * `["p", "<owner_pubkey>"]` for `p:` scopes
    * `["a", "<kind>:<pubkey>:<dtag>"]` for `a:` scopes

### Optional tags

* `["subscription", "<min_sats>"]` — minimum payment in sats; default `0`
* `["expires_in", "<seconds>"]` — recommended entitlement duration; default `31536000`
* `["payment_pubkey", "<hex_pubkey>"]` — payment recipient pubkey; default is the scope owner
* `["title", "<text>"]`
* `["summary", "<text>"]`
* `["image", "<url>"]`
* `["published_at", "<unix_seconds>"]`
* `["payment_descriptor", "<coordinate>", "<relay_hint>"]` — optional pointer to an external payment descriptor (e.g. an addressable plan/processor event standardized elsewhere). A dedicated tag is used here rather than overloading the `a` tag, which already carries the scope pointer.
* `["r", "<url>"]` — optional checkout or info URL

### Ownership rule

The relay MUST verify that the `38110` author matches both the `G` scope owner and the scope pointer.

For `p:` scopes:

* `pubkey` == `<owner_pubkey>` in `G`
* `pubkey` == value of the `p` tag

For `a:` scopes:

* `pubkey` == middle field of the `G` scope reference
* `pubkey` == middle field of the `a` tag value

A mismatched Scope Definition MUST be rejected.

### Example

```jsonc
{
  "kind": 38110,
  "pubkey": "<scope_owner_pubkey>",
  "tags": [
    ["d", "magazine-premium"],
    ["G", "a:30040:<scope_owner_pubkey>:magazine-premium"],
    ["a", "30040:<scope_owner_pubkey>:magazine-premium"],
    ["subscription", "50000"],
    ["expires_in", "31536000"],
    ["title", "Premium magazine access"],
    ["summary", "Subscriber-only issues and archives"]
  ],
  "content": ""
}
```

## Entitled Content

Any event carrying a `G` tag is entitled content and requires an active entitlement for that exact scope.

Events without a `G` tag are open content.

This revision defines **no inheritance**. Replies, comments, embedded media, descendants, and referenced events are not gated unless they themselves carry the same `G` tag.

Only the explicit `G` tag matters.

## Entitlement Request (kind `8110`)

Authored by the subscriber. Requests access to the scope defined by a specific `38110`.

### Required tags

* `["a", "<38110_coordinate>"]`
* `["G", "<scope_ref>"]`

Where `<38110_coordinate>` is:

* `38110:<scope_owner_pubkey>:<dtag>`

### Optional tags

* `["zap", "<9735_event_id>"]` — REQUIRED if `subscription > 0`
* `["p", "<scope_owner_pubkey>"]` — indexing hint

This revision supports one zap receipt per request.

### Example

```jsonc
{
  "kind": 8110,
  "pubkey": "<subscriber_pubkey>",
  "tags": [
    ["a", "38110:<owner_pubkey>:magazine-premium"],
    ["G", "a:30040:<owner_pubkey>:magazine-premium"],
    ["p", "<owner_pubkey>"],
    ["zap", "<9735_event_id>"]
  ],
  "content": ""
}
```

## Entitlement Grant (kind `8102`)

Issued by the relay after successful validation.

### Required tags

* `["p", "<subscriber_pubkey>"]`
* `["a", "<38110_coordinate>"]`
* `["G", "<scope_ref>"]`
* `["expiration", "<unix_seconds>"]`

### Optional tags

* `["e", "<8110_event_id>"]`
* `["zap", "<9735_event_id>"]`
* `["free", "true"]`
* `["amount_paid", "<millisats>"]`

### Example

```jsonc
{
  "kind": 8102,
  "pubkey": "<issuer_pubkey>",
  "tags": [
    ["p", "<subscriber_pubkey>"],
    ["a", "38110:<owner_pubkey>:magazine-premium"],
    ["G", "a:30040:<owner_pubkey>:magazine-premium"],
    ["expiration", "1767225600"],
    ["e", "<8110_event_id>"],
    ["zap", "<9735_event_id>"],
    ["amount_paid", "50000000"]
  ],
  "content": ""
}
```

## Entitlement Revoke (kind `8112`)

Issued by the relay to terminate entitlement for a subscriber and scope.

Revocation is at the `(subscriber_pubkey, scope_ref)` granularity, not at the granularity of an individual `8102` event. The optional `e` and `a` tags are audit references only — entitlement state evaluation per §Entitlement State Semantics looks at the latest transition for the tuple, regardless of which specific grant the revoke names.

### Required tags

* `["p", "<subscriber_pubkey>"]`
* `["G", "<scope_ref>"]`

### Optional tags

* `["a", "<38110_coordinate>"]`
* `["e", "<8102_event_id>"]` — audit reference only
* `["reason", "<text>"]`

### Example

```jsonc
{
  "kind": 8112,
  "pubkey": "<issuer_pubkey>",
  "tags": [
    ["p", "<subscriber_pubkey>"],
    ["G", "a:30040:<owner_pubkey>:magazine-premium"],
    ["a", "38110:<owner_pubkey>:magazine-premium"],
    ["reason", "refund issued"]
  ],
  "content": ""
}
```

## Entitlement State Semantics

Entitlement state is defined per tuple:

* `(subscriber_pubkey, scope_ref)`

and **not** per individual grant event.

A conforming relay or client MUST evaluate entitlement state using only `8102` and `8112` events signed by a trusted issuer key.

For a given `(subscriber_pubkey, scope_ref)`:

1. Collect all matching `8102` and `8112` events.
2. Order them by:

    * ascending `created_at`
    * then ascending event id as deterministic tie-break
3. The latest transition wins.

> Note: at equal `created_at`, this NIP defines the lexicographically **greater** event id as the winner. This is **inverted** relative to NIP-01 replaceable-event semantics (which keep the lower id). `8102` and `8112` are regular events, not replaceable, so this NIP is free to define its own rule; the inversion is called out so implementers do not silently default to NIP-01's tie-break.

Interpretation:

* latest transition is `8102` and `expiration` is in the future → entitlement is active
* latest transition is `8102` and `expiration` is in the past → entitlement is inactive
* latest transition is `8112` → entitlement is inactive until a later `8102` appears

An older unexpired `8102` MUST NOT become active again merely because a newer `8102` was revoked.

## Scope Definition Revision Semantics

`38110` is parameterized-replaceable. Newer revisions supersede older ones for future requests, but already-issued entitlements are unaffected.

When validating a paid entitlement request, the relay MUST select the applicable `38110` revision as:

> the latest Scope Definition for the same `(scope_owner_pubkey, dtag)` whose `created_at` is not greater than the payment evidence time.

For zap-backed requests, the payment evidence time is the `created_at` of the referenced `9735` receipt. For free-tier requests (no payment evidence), the payment evidence time is the `created_at` of the `8110` request itself.

This prevents a subscriber from backdating the `8110` request to force an older cheaper plan, and prevents a scope owner from retroactively changing terms after payment.

## Payment Verification

When processing a non-free entitlement request, the relay MUST verify:

1. the referenced Scope Definition coordinate exists
2. the request’s `G` tag matches the applicable `38110`
3. the referenced event is a valid `kind:9735`
4. the receipt’s `description` tag decodes to a valid signed zap request
5. the embedded zap request `pubkey` equals the `8110` author pubkey
6. the embedded zap request references the relevant `38110` coordinate in an `a` tag
7. the receipt’s payee matches the scope’s payment recipient:

    * if `payment_pubkey` is present on the applicable `38110`, the receipt recipient MUST equal that pubkey
    * otherwise the receipt recipient MUST equal the scope owner
8. the receipt amount is at least `subscription * 1000` millisats for the applicable `38110`
9. the relay's freshness/settlement policy is satisfied (e.g. the receipt's `created_at` is within an operator-configured window, the bolt11 invoice has settled, replay-window checks have passed)

For a free scope (`subscription` absent or `0`), the relay MAY mint an entitlement without payment evidence. The grant SHOULD carry `["free", "true"]`.

### Computing `expiration` on `8102`

The relay SHOULD compute the grant's `expiration` as `created_at + expires_in`, using the `expires_in` value from the applicable `38110` revision (default `31536000` seconds / one year). This rule applies to both paid and free grants.

On validation failure, the relay MUST reject the request and MUST NOT mint `8102`.

Example rejection:

```json
["OK", "<8110_event_id>", false, "restricted: invalid payment evidence"]
```

## Payment Evidence Reuse and Idempotency

A zap receipt is evidence that a subscriber paid for a scope. It is **not** a single-use mint token tied to one relay.

Multiple relays enforcing the same scope MAY honor the same valid receipt independently.

Relays MUST NOT reject an entitlement request merely because the same receipt may already have been honored on another relay.

A receipt MUST NOT be accepted for a different scope than the one it references through the applicable `38110` coordinate and the matching `G` tag.

Repeated use of the same receipt for the same `(subscriber_pubkey, scope_ref)` on one relay SHOULD be treated as idempotent and MUST NOT by itself extend entitlement duration or create a new paid term.

A relay MAY reject such a request as duplicate, or MAY treat it as a successful no-op.

## Relay Behavior

### Discovery

A relay SHOULD serve `38110` Scope Definitions to unauthenticated clients unless local policy forbids it.

### Reads

To serve entitled content, the relay MUST know the requester’s pubkey via [NIP-42](42.md) AUTH or equivalent authenticated session state.

For an event carrying `["G", "<scope_ref>"]`, the relay MUST check whether the authenticated pubkey holds an active entitlement for that exact scope.

If not, the relay MUST NOT serve the event. The relay SHOULD behave as if the event does not exist.

Open content remains subject to normal relay policy.

### Writes

| Kind               | Requirement              |
| ------------------ | ------------------------ |
| `38110`            | scope-owner match        |
| `8110`             | authenticated subscriber |
| `8102`, `8112`     | issuer key only          |
| `G`-tagged content | relay local policy       |
| other kinds        | relay local policy       |

This NIP does not standardize who may publish into a scope. That remains local relay policy.

## Subscriber Export (NIP-51)

A scope owner MAY export active subscribers as a portable [NIP-51](51.md) `kind:30000` categorized people list.

Suggested structure:

* `pubkey`: scope owner
* `["d", "<identifier>"]`
* `["G", "<scope_ref>"]`
* repeated `["p", "<subscriber_pubkey>", "<relay_hint>", "<petname>"]`
* optional `["title", ...]`
* optional `["description", ...]`
* optional `["exported_at", "<unix_seconds>"]`

The export is authored by the scope owner, not by the issuer key.

Clients SHOULD derive export contents from the active entitlement state for `(subscriber_pubkey, scope_ref)` tuples rather than relying on a proprietary relay endpoint.

**Confidentiality.** A `kind:30000` event published in the clear leaks subscriber pubkeys to anyone who can read it. Scope owners who want to keep their subscriber list private SHOULD encrypt the `p` tags into the event's `.content` field per [NIP-51](51.md), or distribute the export out-of-band rather than publishing it to public relays.

## Relationship to Other NIPs

This NIP composes with existing relay metadata, auth, payment, and list mechanisms instead of replacing them.

[NIP-11](11.md) is the place for coarse relay discovery and arbitrary extra relay fields. [NIP-42](42.md) defines relay authentication and the standard `auth-required:` / `restricted:` flows. [NIP-57](57.md) defines zap receipts (`kind:9735`) used here as payment evidence. [NIP-51](51.md) defines `kind:30000` categorized people lists used here for subscriber export.

In-flight proposals for relay-side gated discovery (e.g. a NIP-11 access-control extension) and addressable payment descriptors are complementary layers: they can tell a client that content is locked and how to pay, but they do not define the scoped entitlement state standardized here. A scope definition can point at such a descriptor via the optional `payment_descriptor` tag.

## Client Behavior

A conforming client SHOULD:

1. discover Scope Definitions with `{"kinds":[38110], "#G":["<scope_ref>"]}` when needed
2. on login, subscribe to the user’s entitlement transitions:

    * `{"kinds":[8102,8112], "#p":["<self_pubkey>"]}`
3. when encountering content with a `G` tag and no active entitlement, show the matching scope’s `title`, `summary`, and subscribe UI from the applicable Scope Definition
4. on subscribe:

    * construct a [NIP-57](57.md) zap request referencing the `38110` coordinate in an `a` tag
    * pay the invoice
    * await the `9735` receipt
    * publish `8110`
    * await and verify `8102`
5. verify every `8102` and `8112` against the trusted issuer set before honoring it

## Security Considerations

* **Creator-paid model.** Payment is made to the scope owner or the scope owner’s designated payment recipient, not to the relay.
* **Relay-local enforcement.** Entitlements are enforced locally by each relay, even when the same receipt may be honored across multiple relays for the same scope.
* **Public subscription facts.** `8110`, `8102`, `8112`, and subscriber exports reveal membership unless wrapped by some other mechanism.
* **Explicit scoping only.** This revision gates only events explicitly tagged with `G`.
* **Payment-time binding.** Applicable scope terms are chosen by payment evidence time, not by `8110.created_at`.
* **Issuer trust.** Clients MUST verify issuer pubkeys before treating `8102` or `8112` as authoritative.
* **Anonymous payments.** Anonymous zaps MUST NOT satisfy an entitlement request because the payer cannot be bound to the subscriber pubkey.

## Example Flow

```text
Subscriber          Scope owner         Relay           LNURL / processor
   │                     │                │                │
   │  read 38110 ◄───────┤ ◄──────────────┤                │
   │                     │                │                │
   │  build 9734 zap request             │                │
   │  (tag: a=38110:<owner>:<dtag>)      │                │
   │  pay invoice ───────────────────────────────────────► │
   │                     │                │                │
   │  9735 receipt ◄──────────────────────────────────────  │
   │                     │                │                │
   │  8110 + [zap,<id>] ─────────────────►│                │
   │                     │                │                │
   │                     │                │ verify 9735    │
   │                     │                │ verify scope   │
   │                     │                │ mint 8102      │
   │  8102 ◄───────────────────────────── │                │
   │                     │                │                │
   │ read G-tagged content (AUTH + active 8102 required)  │
```

## Acknowledgements

This NIP builds on [NIP-11](11.md), [NIP-42](42.md), [NIP-51](51.md), and [NIP-57](57.md).
