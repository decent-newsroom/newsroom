# Gated Access And Payments

Status: draft contract specification. Supersedes the kind allocations in
`02-audiences-and-payment-targets.md` and the deleted draft docs (NIP-SB,
`documentation/Subscriptions/` — ReWire grant model; see `00-refactor-plan.md`
Decision Log D2/D3). A fresh NIP documenting the protocol as actually
implemented will be written in Phase 6.

This spec is the **contract** between four parties: the Unfold bundle (this
repo), the payment bridge (separate repo), the mint (separate repo), and the
gated relay (third party). Every cross-party message is a Nostr event.

## Kind Allocation

| Kind | Name | Signer | Replaceability | Stored? |
|---|---|---|---|---|
| `38133` | Publication Payment Targets | Publication owner | Addressable (`d`) | Yes |
| `30879` | Scope / Audience Definition | Publication owner | Addressable (`d`) | Yes |
| `8879` | Access Attestation (payment receipt) | Payment bridge | Regular | Restricted (see Privacy) |
| `28877` | Holder Assertion | Subscriber | Ephemeral | No |
| `28878` | Access Authorization (token) | Mint | Ephemeral | No |

Superseded kinds — never implement, never add to `KindsEnum`:
`38110`, `30133`, `8110`, `8102`, `8103`, `8112`, `8113`, `18101`, `8101`.

Personal payment targets (`kind:10133`, NIP-A3) remain valid and unrelated;
`38133` is its addressable, publication-scoped sibling.

## Trust Model

- The **relay** has trusted **mint** npubs on record.
- The **mint** has trusted **payment bridge** npubs on record.
- The **bridge** knows the publication's payment targets (`38133`) and
  audiences (`30879`) and has payment-success hooks configured.
- DN operates bridge and mint in v1; the model allows other operators later.

```
subscriber ──pays──▶ bridge ──8879──▶ mint ◀──28877── subscriber
                                        │
                                        └──28878──▶ subscriber ──token+REQ──▶ relay
```

## Events

### `kind:38133` — Publication Payment Targets

Addressable variant of NIP-A3 `payto:` payment targets (RFC-8905), scoped to
one publication.

Required tags:

```json
["d", "<descriptor-dtag>"]
["a", "30040:<owner_pubkey>:<publication-dtag>"]
["payto", "<type>", "<authority>"]
```

- `payto` is repeatable (lightning, bitcoin, monero, bank handles, …) and
  reuses NIP-A3 semantics exactly, so `PaymentTargetService` parsing applies.
- Optional: `title`, `summary`, `published_at`.
- Signer must equal the publication owner pubkey.

### `kind:30879` — Scope / Audience Definition

A single event that is simultaneously (a) the subscription-tier offer shown
to users and (b) the access requirement referenced by gated events. Tag
semantics deliberately reuse the classifieds/commerce vocabulary (NIP-99
style `title`/`summary`/`price`/`image`/`published_at`) rather than inventing
a new abstraction.

Required tags:

```json
["d", "<audience-dtag>"]
["a", "30040:<owner_pubkey>:<publication-dtag>"]
["title", "<offer title>"]
["price", "<amount>", "<currency>", "<period?>"]
```

- `price` repeatable for multi-currency pricing; `SATS` allowed.
- Optional: `summary`, `image`, `expires_in` (access duration in seconds),
  `payment_targets` (coordinate of a `38133` overriding the publication
  default for this audience).
- The **scope coordinate** of an audience is
  `30879:<owner_pubkey>:<audience-dtag>`.

Decision (resolves Q1): `30879` is deliberately **not** NIP-99 `30402`.
Classifieds describe sellable goods/services with seller–buyer handoff,
`location`/`g` tags, and a `status: active|sold` lifecycle — none of which
apply to a standing offer of digital access that never sells out. Reusing
the kind would also surface audience offers as malformed listings in live
classifieds clients (Shopstr, Plebeian Market, Amethyst marketplace views)
and force gating tooling to filter marketplace noise. Only the tag
*vocabulary* is reused: `title`, `summary`, `image`, `published_at`, and the
NIP-99 price array format `["price", "<number>", "<currency>", "<frequency>"]`.
`location`, `g`, and `status` are not used. Include an
`["alt", "Audience tier for a gated publication"]` tag so foreign clients
render something sensible.

### Scope tag on gated content

Gated events (articles `30023`, indexes `30040`/`30041`) carry:

```json
["s", "30879:<owner_pubkey>:<audience-dtag>"]
```

- Proposal: single-letter `s` so relays can index it (subject to Q3 —
  confirm with the relay implementer; prior drafts used `scope`/`G`).
- Repeatable: content may belong to several audiences; possession of a valid
  token for **any** listed scope grants read access.
- Publishing guard: events carrying `s` MUST be sent only to the publication
  home relay (Phase 5 chokepoint).

### `kind:8879` — Access Attestation

Signed by the payment bridge after a successful payment. Links a subscriber
pubkey to a purchased scope.

```json
{
  "kind": 8879,
  "pubkey": "<bridge_pubkey>",
  "tags": [
    ["p", "<subscriber_pubkey>"],
    ["s", "30879:<owner_pubkey>:<audience-dtag>"],
    ["expiration", "<unix_seconds>"],
    ["payment", "<method>", "<opaque-reference>"]
  ],
  "content": ""
}
```

- `expiration` derives from the audience `expires_in` at purchase time.
- `payment` carries an opaque reference for reconciliation, never raw payment
  details.
- Delivery: bridge → mint directly (see Privacy). The subscriber may also
  receive a copy as their receipt.

### `kind:28877` — Holder Assertion

Ephemeral, signed by the subscriber, sent to the mint to request a token.
Acts as proof-of-key-possession (the mint must not mint for a pubkey that
did not ask).

```json
{
  "kind": 28877,
  "pubkey": "<subscriber_pubkey>",
  "tags": [
    ["s", "30879:<owner_pubkey>:<audience-dtag>"],
    ["relay", "wss://premium.decentnewsroom.com"],
    ["expiration", "<now + short window>"]
  ],
  "content": ""
}
```

Fresh `created_at` and short `expiration` provide replay resistance.

### `kind:28878` — Access Authorization

Ephemeral, signed by the mint. The short-lived token the subscriber presents
to the relay.

```json
{
  "kind": 28878,
  "pubkey": "<mint_pubkey>",
  "tags": [
    ["p", "<subscriber_pubkey>"],
    ["s", "30879:<owner_pubkey>:<audience-dtag>"],
    ["relay", "wss://premium.decentnewsroom.com"],
    ["expiration", "<unix_seconds, short TTL>"]
  ],
  "content": ""
}
```

## Relay Behavior (normative for the relay implementer)

1. Relay maintains a configured set of trusted mint pubkeys.
2. Events without an `s` tag: normal relay behavior.
3. Events with an `s` tag are returned only when the requesting connection
   has presented a valid `28878` where:
   - signature valid and signer is a trusted mint,
   - `p` matches the NIP-42-authenticated connection pubkey,
   - `s` matches (one of) the event's `s` tag(s) exactly,
   - `expiration` is in the future.
4. NIP-42 AUTH is required before any gated content is returned.
5. Token transport is **unresolved** (Q2): candidates are a custom
   `["AUTH-TOKEN", <28878-event>]` client message or an extended NIP-42
   exchange. Must be settled with the relay implementer before Phase 6.

## Mint Behavior (normative for the mint repo)

1. Maintains trusted bridge pubkeys.
2. On `28877`: verify signature, freshness, and an unexpired `8879` from a
   trusted bridge matching (`p` = subscriber, `s` = scope).
3. Issue `28878` with short TTL (minutes, not days); clients re-assert to
   refresh. Attestation `expiration` bounds the last refresh.

## Privacy

- `8879` publicly links a person to a paid subscription. It MUST NOT be
  broadcast to public relays. Transport is bridge→mint (and optionally
  bridge→subscriber over NIP-17 DM or direct HTTPS response).
- `28877`/`28878` are ephemeral and sent point-to-point; they never enter
  public relay storage.
- DN analytics must not expose subscriber identity to publication owners
  (aggregate counts only).

## Eligibility

Enabling gating for a publication requires an active DN subdomain
subscription (`PublicationSubdomainSubscription`). That subscription grants
access to the gated relay and to the scope/audience setup in the dashboard.

## AppData Linkage

AppData (`kind:30078`, Spec 01) references the new events by coordinate:

```json
["audience", "30879:<owner_pubkey>:<dtag>", "<relay_hint?>"]
["payment_targets", "38133:<owner_pubkey>:<dtag>", "<relay_hint?>"]
```

(Coordinates updated from Spec 01/02, which predate the kind renumbering.)
