# Relay Pool Management

Relay selection is host-owned: the configured registry supplies purpose-based defaults, while user relay lists and followed authors' relays augment user-scoped reads. The transport layer uses the extracted Nostr client and optional relay gateway packages.

## Registry and URL handling

`src/Service/Nostr/RelayRegistry.php` is the source of configured relay URLs. Parameters in `config/services.yaml` distinguish local, project, profile, content, signer, and chat purposes. `RelayUrlNormalizer` centralizes URL normalization.

LOCAL and PROJECT identify the same default relay through different network paths. LOCAL is the internal Docker URL (`NOSTR_DEFAULT_RELAY`, normally `ws://strfry:7777`); PROJECT is the public browser-facing URL. Server requests avoid querying both aliases. Display APIs preserve public URLs so internal hostnames do not leak into editor/settings data.

## User relay-list resolution

`src/Service/Nostr/UserRelayListService.php` resolves NIP-65 lists in this order:

1. Hot PSR-6/Redis cache, with a one-hour TTL.
2. Durable `UserRelayList` projection in PostgreSQL.
3. Blocking network fetch from profile relays when both stores miss.
4. Registry fallback relays.

The durable projection stores pubkey, read/write relay arrays, the source event timestamp, and the update time. It is a dedicated entity, not a synthetic unsigned row in the raw `event` table. Real kind 10002 events can still be persisted separately by event ingestion. Network refresh upserts the projection; publishing a relay list can seed cache and DB through `seedFromPublishedRelayListEvent()`. Login warming uses `UpdateRelayListMessage`.

`NostrClient::getNpubRelays()` delegates to `UserProfileService::getRelays()`, which calls `UserRelayListService::getRelays()`. Use the latter service's purpose-specific APIs when choosing read/publish targets:

| API | Use |
|---|---|
| `getRelayList()` | Structured server-side list with local/project remapping |
| `getRelayListForDisplay()` | Structured list with public URLs |
| `getRelaysForUser()` / `getRelaysForFetching()` | User-scoped fetch selection |
| `getRelaysForPublishing()` | Publish targets |
| `getRelaysForEventLookupCacheOrDb()` | Interactive lookup without a blocking relay-list fetch; capped at eight by default |

Content selection orders local relay, the user's own relays, the follows relay pool, and registry defaults, deduplicating across layers. The follows pool contains followed authors' write relays, is keyed to the current kind 3 event, and is cached for 30 days. It augments CONTENT selection only and never mutates global registry defaults. The general user relay selection API does not truncate the user's relay list.

## Transport and health

`NostrRelayPool` executes host relay/query contracts using the client factory from `nostr-client-bundle`. URL selection belongs to `RelayRegistry` and `RelaySetFactory`; callers should not recover the registry or health store through transport internals.

`RelayHealthStore` shares failure counts, success timestamps, latency and AUTH status through Redis. Ranking considers failures, recent success, and latency. Configured relays retain health data for seven days; ad hoc relays for one day. Key discovery uses SCAN. Muted relays score zero; repeated failures can auto-mute a relay.

`RelaySetFactory` combines configured and user relays and applies operation-specific health-aware selection. See [Relay Discovery](../Nostr/relay-discovery.md) for NIP-11 metadata and trusted NIP-66 observations.

## Gateway and direct requests

The optional gateway command is supplied by the Composer package `decent-newsroom/relay-gateway-bundle`. With the gateway enabled, the pool routes external reads through Redis stream requests to persistent connections; generic publishing still uses direct connections.

Gateway connections are opened on demand. Shared anonymous connections decline AUTH; user-keyed connections can sign through the signing integration or browser Mercure roundtrip. Queries decompose multi-filter/multi-kind requests into sequential subscriptions and deduplicate returned events. AUTH challenges belong to the individual connection and cannot be cached for another socket.

See [Relay Gateway Service](../Nostr/relay-gateway-service.md), [Direct Relay AUTH](../Nostr/user-scoped-direct-relay-auth.md), [Filter Statistics](../Nostr/relay-filter-stats.md), and [User Activity Log](../Nostr/user-relay-activity-log.md).

## Operations and future work

The admin relay dashboard at `/admin/relay` exposes relay health, worker heartbeats and gateway status. Admin reads use the pool's typed request API.

Further design ideas are retained in [Relay Infrastructure Proposals](../Nostr/relay-improvements.md). Historical review checklists, obsolete helper methods and unmeasured before/after latency tables have been replaced by this current reference.
