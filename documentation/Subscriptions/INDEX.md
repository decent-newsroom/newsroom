# Subscriptions — Documentation Index

Documentation for the ReWire Relay + DN Client subscription system: publisher access, scope subscriptions, and subscriber exports.

## Canonical source of truth

Two documents are normative. When other docs in this folder disagree, these win:

1. **[subscriptions.md](subscriptions.md)** — Product-level spec, glossary, policy, kind allocation.
2. **[rewire-relay-specification.md](rewire-relay-specification.md)** — Normative relay behavior: auth model, write rules, grant issuance, replay protection, bearer quota, free-tier rate limit, version conflicts, issuer-key management.

## Reference & supporting documents

3. **[subscription-events.md](subscription-events.md)** — Event catalog with full JSON examples for every kind.
4. **[subscription-flows.md](subscription-flows.md)** — User-journey diagrams (Guest → Subscriber, Author → Publisher).
5. **[architecture-overview.md](architecture-overview.md)** — High-level architecture: access tiers, revenue streams, DN Client premium services.
6. **[free-tier-feature.md](free-tier-feature.md)** — 0-sat subscription behavior and publisher use cases.

## Authoritative kind numbers

| Kind    | Event               | Signer        | Revoke pair |
|---------|---------------------|---------------|-------------|
| `18101` | Publish Grant       | Relay issuer  | ↔ `8101`    |
| `8101`  | Publish Revoke      | Relay issuer  | ↔ `18101`   |
| `38110` | Scope Definition    | Scope owner   | —           |
| `8110`  | Subscribe Request   | Subscriber    | —           |
| `8102`  | Membership Grant    | Relay issuer  | ↔ `8112`    |
| `8112`  | Membership Revoke   | Relay issuer  | ↔ `8102`    |
| `8103`  | Whitelist Grant     | Scope owner   | ↔ `8113`    |
| `8113`  | Whitelist Revoke    | Scope owner   | ↔ `8103`    |
| `30000` | Subscriber export (NIP-51) | Scope owner | —      |
| `9735`  | Zap receipt (NIP-57)       | LNURL service | —    |

Replaceability:
- `18101` is replaceable (one active publisher grant per pubkey; renewals replace).
- `38110` is parameterized-replaceable (one per `(pubkey, d)`; newer scope-def revisions replace older).
- All other kinds are regular (each event is an independent audit record).

## Authoritative auth model

* NIP-42 AUTH is required on every connection to ReWire Relay.
* After AUTH, open content (no `scope` tag) is readable by any authenticated pubkey.
* Gated content (carries `scope` tag) requires an active membership grant for that scope.
* Writes of "publishable" kinds require an active publisher grant; `kind 8110` (subscribe request) is the only write allowed without a publisher grant.
* The `scope` tag is multi-character and requires explicit relay index configuration (see relay spec §Tag Indexing Requirement).

