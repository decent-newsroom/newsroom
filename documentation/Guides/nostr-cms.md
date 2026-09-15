# Nostr publishing in Decent Newsroom

Decent Newsroom provides editing, discovery, curation, and hosting around signed Nostr events. An article can be read by compatible clients wherever a relay retains it. The application also keeps local databases, caches, and application-specific state such as subscriptions and sessions.

## Identity and signatures

A public key identifies a Nostr author. A signature proves that the corresponding private key signed an event; it does not establish a person's real-world identity or the truth of the content.

User publishing uses a NIP-07 browser extension or NIP-46 remote signer. The signer holds the user's key and returns a signed event. Newsroom verifies and processes that event.

NIP-01 defines the event ID as the SHA-256 hash of the UTF-8 JSON serialization of:

```text
[0, pubkey, created_at, kind, tags, content]
```

The signature covers that ID. Hashing the whole event object after removing `sig` is not the NIP-01 procedure. See the repository's [NIP-01 reference](../NIP/01.md) for serialization and verification rules.

## Content types and revisions

| Content | Kind | Reference |
|---|---|---|
| Profile | `0` | [NIP-01](../NIP/01.md) |
| Article / draft | `30023` / `30024` | [NIP-23](../NIP/23.md) |
| Publication index | `30040` | [NKBIP-01](../NKBIP/01.md) |
| Publication content | `30041` | [NKBIP-01](../NKBIP/01.md) |
| Highlight | `9802` | [NIP-84](../NIP/84.md) |
| Comment | `1111` | [NIP-22](../NIP/22.md) |
| Relay list | `10002` | [NIP-65](../NIP/65.md) |

An article coordinate is `30023:<author-pubkey>:<d-tag>`. A newer event with the same kind, author, and `d` tag supersedes the previous revision. Each revision has its own event ID. A different slug creates a different coordinate.

Draft events are a separate kind, not inherently private or encrypted. The editor can send them to relays; do not treat the draft label as an access-control guarantee.

## Publishing and reading

1. The editor prepares content and metadata, then asks the user's signer to sign.
2. The publish endpoint verifies the signed event and persists the article locally.
3. It publishes to the selected relays, normally the author's write relays plus the instance relay. Essayist publishing options can change the destinations.
4. Relay subscriptions and background jobs ingest events from the network. PostgreSQL projections, graph records, and Redis views support reading and discovery.

A successful local save and successful relay publication are separate results. The local index represents content the instance has received and accepted; it is not a complete mirror of Nostr.

## Magazines and hosting

The magazine wizard publishes a kind `30040` root index referencing kind `30040` category indices. Categories reference articles by coordinate. Kind `30041` is publication content, such as book chapters; it is not the kind used for the wizard's category indices.

Unfold serves hosted publications using these references and local content projections. Referencing an article in a magazine preserves the original author and signature. See [magazine creation](../Newsroom/magazine-wizard.md) and [Unfold](../Unfold/unfold.md).

## Retention and deletion

Relays and instance operators choose what to accept, store, and display. Sending an event to multiple relays improves availability but does not guarantee permanent storage. External images and other media also need their own storage.

NIP-09 kind `5` events request deletion of event IDs or addressable coordinates. Newsroom checks ownership, processes valid requests, and retains tombstones to prevent suppressed versions from being ingested again. Remote deletion remains dependent on relay behavior; an operator cannot erase every copy on the network. See [deletion handling](../Nostr/nip-09-deletion.md).

Continue with [getting started](getting-started.md), [architecture](architecture.md), or the [feature index](../INDEX.md).
