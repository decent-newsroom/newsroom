# Audiences And Payment Targets

## Goal

Represent publication subscription tiers as Nostr-native addressable events and let each Unfold publication define payment targets that can differ from the owner's personal payment targets.

## Audience Model

Audiences reuse the existing Scope Definition model:

- Kind: `38110`
- Replaceability: parameterized replaceable by `d` tag.
- Signer: publication owner.
- Scope: the Unfold publication, using a `G` tag.

Expected enum addition:

```php
KindsEnum::SCOPE_DEFINITION = 38110
```

Minimum required tags:

```json
["d", "<audience-dtag>"]
["G", "a:30040:<owner_pubkey>:<publication-dtag>"]
["a", "30040:<owner_pubkey>:<publication-dtag>"]
["title", "<card title>"]
["summary", "<card description>"]
```

Optional tags:

```json
["price", "<amount>", "<currency>", "<period?>"]
["subscription", "<min_sats>"]
["expires_in", "<seconds>"]
["image", "<url>"]
["payment_descriptor", "30133:<owner_pubkey>:<dtag>", "<relay_hint?>"]
["published_at", "<unix_seconds>"]
```

`price` is repeatable so an audience can be priced in multiple currencies. Currency values are uppercase ISO-like codes where possible, with `SATS` allowed for Lightning/BTC-native pricing.

If a `SATS` price exists, publish `subscription` with the same minimum sats value for compatibility with the existing subscription-scope documents and NIP-SB-style tooling.

Example:

```json
{
  "kind": 38110,
  "content": "",
  "tags": [
    ["d", "supporter"],
    ["G", "a:30040:<owner_pubkey>:daily-letters"],
    ["a", "30040:<owner_pubkey>:daily-letters"],
    ["title", "Supporter"],
    ["summary", "Monthly support with subscriber-only notes."],
    ["price", "50000", "SATS", "month"],
    ["price", "5", "USD", "month"],
    ["subscription", "50000"],
    ["expires_in", "2592000"],
    ["payment_descriptor", "30133:<owner_pubkey>:daily-letters-payments", "wss://relay.example.com"]
  ]
}
```

## Publication Payment Descriptor

Personal payment targets already use replaceable `kind:10133`, which is not addressable by `d` tag. Unfold needs a publication-specific addressable partner so a publication can use different targets than the owner's profile.

Provisional kind:

```php
KindsEnum::PUBLICATION_PAYMENT_TARGETS = 30133
```

Event rules:

- Kind: `30133`
- Replaceability: parameterized replaceable.
- Signer: publication owner.
- Required `d` tag.
- Required `G` tag for the publication scope.
- Reuses NIP-A3 `payto` tag semantics.

Required tags:

```json
["d", "<descriptor-dtag>"]
["G", "a:30040:<owner_pubkey>:<publication-dtag>"]
["a", "30040:<owner_pubkey>:<publication-dtag>"]
```

Payment target tags:

```json
["payto", "lightning", "publication@example.com"]
["payto", "bitcoin", "bc1..."]
["payto", "revolut", "example-handle"]
```

Optional tags:

```json
["title", "<display title>"]
["summary", "<display summary>"]
["published_at", "<unix_seconds>"]
```

## AppData Linkage

AppData links to payment and audience events by coordinate:

```json
["audience", "38110:<owner_pubkey>:supporter", "<relay_hint?>"]
["payment_targets", "30133:<owner_pubkey>:daily-letters-payments", "<relay_hint?>"]
```

Audiences may override the publication default payment descriptor with their own `payment_descriptor` tag. When both exist, the audience descriptor wins for that audience card.

## Admin Behavior

The owner admin should support:

- Creating and editing audience title, summary, prices, duration, and optional image.
- Publishing a signed `38110` event for each audience.
- Creating and editing publication payment targets.
- Publishing a signed `30133` event for publication targets.
- Updating AppData so the new audience/payment descriptor coordinates are listed.

Deleting an audience should mean publishing a new AppData revision without that `audience` tag. Hard deletion of old Nostr events is out of scope.