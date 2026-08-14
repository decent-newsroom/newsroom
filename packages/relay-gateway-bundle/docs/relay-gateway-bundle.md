# RelayGatewayBundle

`decent-newsroom/relay-gateway-bundle` owns the long-running `app:relay-gateway` command and the Redis Stream client used by request workers.

The bundle is backed by `decent-newsroom/nostr-client-bundle` and `innis/nostr-client`; the host app supplies newsroom-specific integrations through contracts:

- `RelayUrlResolverInterface` maps public project relay URLs to Docker-internal connection URLs and back for AUTH tags.
- `AuthChallengeSignerInterface` signs user-keyed NIP-42 AUTH challenges through IdentityBundle remote signer sessions first, then the existing Mercure browser roundtrip.
- `GatewayHealthRecorderInterface`, `GatewayFilterStatsRecorderInterface`, and `GatewayActivityRecorderInterface` keep the existing Redis-backed admin and user relay diagnostics populated.

## Runtime shape

The command preserves the existing Redis protocol:

- `relay:requests` receives `query` and `publish` requests.
- `relay:control` receives `warm` and `close` user-connection lifecycle commands.
- `relay:responses:{id}` carries query/publish responses and expires after the response TTL.
- `relay_gateway:heartbeat` plus cursor keys expose process health to the admin dashboard.

Shared anonymous relay connections are opened on demand and idle-pruned after `--on-demand-idle-timeout` (default 300 seconds). Configured shared relays are not opened at startup unless the command is launched with `--prewarm-shared-relays`.

User warm commands open user-keyed connections that can satisfy AUTH-gated relays without mutating the global relay registry. Anonymous shared connections decline AUTH challenges; if a relay closes with an auth-related message, the host health recorder marks that relay as AUTH-required/failed for diagnostics and later user-keyed routing.

## Query execution

The gateway accepts the same Redis request payload as before, including a single `filter` field or a `filters` list. Before sending REQ frames to a relay, it expands the logical query into relay-friendly units:

- each top-level filter is sent as its own subscription;
- any filter with multiple `kinds` is split into one subscription per kind;
- all subscriptions for a relay reuse the same pooled WebSocket connection;
- each subscription is closed with `unsubscribe()` before the next one starts;
- events are deduplicated by event id before the single Redis response is written.

This avoids relay-hostile multi-filter REQs while keeping the caller-facing result shape unchanged.

## Host compatibility

Existing application code can continue type-hinting `App\Service\Nostr\RelayGatewayClient`; that class is now a thin subclass of `DecentNewsroom\RelayGatewayBundle\Service\RelayGatewayClient`.

The legacy app-side `src/Command/RelayGatewayCommand.php` and `src/Service/Nostr/GatewayConnection.php` have been removed. `config/bundles.php` registers `DecentNewsroom\RelayGatewayBundle\RelayGatewayBundle`, and `config/services.yaml` wires host adapters under `src/RelayGateway/`.