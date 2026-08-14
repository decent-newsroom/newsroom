# Relay Pool Management

## Overview

The relay pool is a two-tier, health-aware, user-context-aware relay infrastructure.

**Tier 1 (Base):** Local strfry relay serves all anonymous/read-only traffic. Subscription workers operate here.

**Tier 2 (User):** When a user logs in, the pool activates their NIP-65 relay list (kind 10002) for personalized reading and publishing.

## Components

### RelayRegistry (`src/Service/Nostr/RelayRegistry.php`)

Single source of truth for all relay URLs. Replaces four previously scattered hardcoded constants. Configured via `services.yaml` parameters:

| Purpose | Example Relays |
|---------|---------------|
| `LOCAL` | `ws://strfry:7777` (from `NOSTR_DEFAULT_RELAY` env) |
| `PROFILE` | `wss://purplepag.es`, `wss://relay.primal.net`, `wss://relay.damus.io` |
| `CONTENT` | `wss://theforest.nostr1.com`, `wss://relay.damus.io`, `wss://nos.lol` |
| `PROJECT` | `wss://relay.decentnewsroom.com` |
| `SIGNER` | `wss://relay.nsec.app`, `wss://relay.decentnewsroom.com` |
| `USER` | Dynamic per-user (NIP-65 relay lists) |

LOCAL and PROJECT are the same physical relay accessed via different network paths: LOCAL is the internal Docker URL for server code, PROJECT is the public `wss://` URL for users.

### RelayHealthStore (`src/Service/Nostr/RelayHealthStore.php`)

Redis-backed per-relay health tracking: success/failure counts, latency, AUTH status. Shared across PHP workers. Powers health-based relay ranking and the admin dashboard.

### UserRelayListService (`src/Service/Nostr/UserRelayListService.php`)

Stale-while-revalidate resolution for user relay lists:
1. Redis cache (fastest)
2. Database (durable stale copy)
3. Network fetch from known relays
4. Fallback to content relays

On successful network fetch, persists the kind 10002 event to the database for durability. Relay list warming is triggered async on login via `UpdateRelayListMessage`.

### Relay Gateway (`packages/relay-gateway-bundle/src/Command/RelayGatewayCommand.php`)

Optional persistent WebSocket connection pool. Feature-flagged via `RELAY_GATEWAY_ENABLED` and backed by `nostr-client-bundle`.

- Shared anonymous connections: opened on demand, idle-pruned quickly, and only prewarmed when `--prewarm-shared-relays` is explicitly set
- User-keyed connections: warmed and closed via Redis Stream control messages
- Query execution: multi-filter and multi-kind reads are decomposed into sequential single-filter subscriptions over the same relay connection, then deduplicated for the caller
- NIP-42 AUTH: user-keyed sockets sign through IdentityBundle NIP-46 first, then the existing browser Mercure SSE roundtrip; anonymous shared sockets decline AUTH challenges
- Communication: FrankenPHP workers <-> gateway via Redis Streams
- `NostrRelayPool` routes external reads through the gateway when enabled; its generic publish path still uses direct connections

### RelaySetFactory (`src/Service/Nostr/RelaySetFactory.php`)

Builds relay sets for specific operations, combining registry relays with user relays, ranked by health score.

## Admin Dashboard

Route: `/admin/relay`

Shows pool status, per-relay health scores, AUTH status, latency, last success/failure times, subscription worker heartbeats, and gateway status.

## Lessons Learned

- **AUTH is per-connection**: NIP-42 AUTH challenges cannot be cached/replayed. The gateway authenticates user-keyed connections once and holds them open; anonymous shared connections now decline AUTH rather than spending throwaway identities.
- **Publishing direct, reading via gateway**: Gateway timeouts were too problematic for publishes (TLS+AUTH could exceed execution limits). Publishes go direct to each relay independently.
- **Sequential REQs beat multifilter REQs**: many relays struggle with complex multi-filter requests, so the gateway sends one decomposed filter/kind at a time on an already-open relay connection.
- **Tag filter passthrough**: `#e`, `#p`, `#t`, `#d`, `#a` tag filters were previously silently dropped in gateway routing, causing unfiltered results. Fixed in the gateway command and `NostrRequestExecutor::buildFilterFromArray`.
- **URL normalization**: Trailing slash differences between config and user relay lists caused shared connection lookup misses; relay URL normalization now happens at the registry/adapter boundary.
- **Stream initialization**: `xRead('$')` on Redis streams caused the gateway to never consume messages. Fixed via `xRevRange` for robust initialization.
