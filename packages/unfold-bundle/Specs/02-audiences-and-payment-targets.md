# Audiences And Payment Targets

> **Authoritative kinds:** audiences use `kind:30879` and publication payment
> targets use `kind:38133`. Their cross-service access contract is defined in
> `06-gated-access-and-payments.md`.

## Goal

Represent publication subscription tiers as Nostr-native addressable events and let each Unfold publication define payment targets that can differ from the owner's personal payment targets.

## Audience Model

Audiences are publication-scoped access-offer definitions:

- Kind: `30879`
- Replaceability: parameterized replaceable by `d` tag.
- Signer: publication owner.
- Scope: the Unfold publication, using its `a` coordinate.

Expected enum addition:

```php
KindsEnum::SCOPE_DEFINITION = 30879
```

Minimum required tags:

```
["d", "<audience-dtag>"]
["a", "30040:<owner_pubkey>:<publication-dtag>"]
["title", "<card title>"]
["price", "<amount>", "<currency>", "<period?>"]
```

Optional tags:

```
["summary", "<card description>"]
["expires_in", "<seconds>"]
["image", "<url>"]
["payment_targets", "38133:<owner_pubkey>:<dtag>", "<relay_hint?>"]
["published_at", "<unix_seconds>"]
```

`price` is repeatable so an audience can be priced in multiple currencies. Currency values are uppercase ISO-like codes where possible, with `SATS` allowed for Lightning/BTC-native pricing.

Example:

```json
{
  "kind": 30879,
  "content": "",
  "tags": [
    ["d", "supporter"],
    ["a", "30040:<owner_pubkey>:daily-letters"],
    ["title", "Supporter"],
    ["summary", "Monthly support with subscriber-only notes."],
    ["price", "50000", "SATS", "month"],
    ["price", "5", "USD", "month"],
    ["expires_in", "2592000"],
    ["payment_targets", "38133:<owner_pubkey>:daily-letters-payments", "wss://relay.example.com"],
    ["alt", "Audience tier for a gated publication"]
  ]
}
```

## Publication Payment Descriptor

Personal payment targets already use replaceable `kind:10133`, which is not addressable by `d` tag. Unfold needs a publication-specific addressable partner so a publication can use different targets than the owner's profile.

Expected enum addition:

```php
KindsEnum::PUBLICATION_PAYMENT_TARGETS = 38133
```

Event rules:

- Kind: `38133`
- Replaceability: parameterized replaceable.
- Signer: publication owner.
- Required `d` tag.
- Reuses NIP-A3 `payto` tag semantics.

Required tags:

```
["d", "<descriptor-dtag>"]
["a", "30040:<owner_pubkey>:<publication-dtag>"]
["payto", "<type>", "<authority>"]
```

Optional tags:

```
["title", "<display title>"]
["summary", "<display summary>"]
["published_at", "<unix_seconds>"]
```

## AppData Linkage

AppData links to payment and audience events by coordinate:

```
["audience", "30879:<owner_pubkey>:supporter", "<relay_hint?>"]
["payment_targets", "38133:<owner_pubkey>:daily-letters-payments", "<relay_hint?>"]
```

Audiences may override the publication default payment targets with their own
`payment_targets` tag. When both exist, the audience reference wins for that
audience card.

## Admin Behavior

The owner admin should support:

- Creating and editing audience title, summary, prices, duration, and optional image.
- Publishing a signed `30879` event for each audience.
- Creating and editing publication payment targets.
- Publishing a signed `38133` event for publication targets.
- Updating AppData so the new audience/payment-target coordinates are listed.

Deleting an audience should mean publishing a new AppData revision without that `audience` tag. Hard deletion of old Nostr events is out of scope.

## Audience Preview Before Access Integration

Audience and payment-target management can ship before the payment bridge, mint,
and gated relay are available. The publication UI renders published audiences as
**Gated access coming soon**, with their configured offer details. It may offer
a notify-me or interest action, but must not offer checkout, imply a purchase
creates access, or report subscriber and revenue counts.

An audience event is public publication configuration, not an entitlement. Until
the relay scope-tag contract and the central home-relay-only publishing guard
are implemented, authors cannot attach an `s` scope tag to articles or indexes
and no content is presented as access-restricted.
