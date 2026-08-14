# Relay Gateway Bundle

`decent-newsroom/relay-gateway-bundle` is a reusable Symfony bundle for running a long-lived Nostr relay gateway behind Redis Streams.

The bundle keeps relay WebSocket connections alive in a console command while request workers communicate with it through Redis. It uses `decent-newsroom/nostr-client-bundle` for the underlying relay transport and keeps host-specific relay policy, signing, and diagnostics behind contracts.

## What It Owns

- The `app:relay-gateway` console command.
- Redis stream protocol between request workers and the gateway process.
- A request-worker client for query, publish, warm, close, and health checks.
- Persistent user-keyed and anonymous shared relay connection pools.
- NIP-42 AUTH challenge handling through a host-provided signer contract.
- Gateway-level connection limits, idle cleanup, heartbeat writes, and response TTLs.
- Optional health, activity, and filter-stat recorder hooks.

## What It Does Not Own

- Global relay registry policy or per-user relay selection.
- Nostr signing implementation details.
- Event persistence, moderation, article projection, or Redis read models.
- Browser/Mercure relay AUTH fallback.
- Direct fallback relay reads when the gateway is disabled.

If the host does not provide adapters, the bundle installs conservative null or passthrough implementations for its optional contracts.

## Main Classes

| Area | Class |
|---|---|
| Bundle entry point | `DecentNewsroom\RelayGatewayBundle\RelayGatewayBundle` |
| Configuration | `DependencyInjection\Configuration`, `RelayGatewayExtension` |
| Gateway command | `Command\RelayGatewayCommand` |
| Request-worker client | `Service\RelayGatewayClient` |
| AUTH challenge helper | `Service\GatewayAuthChallengeHandler` |
| Query result collector | `Service\CollectingEventHandler` |
| Default relay resolver | `Service\PassthroughRelayUrlResolver` |
| Default signer | `Service\NullAuthChallengeSigner` |
| Default recorders | `Service\NullGatewayRecorder` |

## Public Contracts

- `RelayUrlResolverInterface` - normalizes relay URLs for connection, AUTH, and optional shared prewarm.
- `AuthChallengeSignerInterface` - signs a relay AUTH challenge for a user pubkey and returns a kind `22242` event, or `null` on failure.
- `GatewayHealthRecorderInterface` - records relay success, failure, received events, AUTH requirement, and AUTH status.
- `GatewayActivityRecorderInterface` - records user-keyed AUTH and publish activity.
- `GatewayFilterStatsRecorderInterface` - computes privacy-preserving filter signatures and records request, EOSE, and timeout stats.

## Redis Streams

The client and command communicate through these keys:

| Key | Purpose |
|---|---|
| `relay:requests` | Query and publish requests from request workers. |
| `relay:control` | Fire-and-forget lifecycle commands such as `warm` and `close`. |
| `relay:responses:{id}` | Per-correlation-id response stream, expired after use. |

The gateway writes heartbeat state while running so request workers and operators can detect whether the process is alive.

## Configuration

```yaml
# config/packages/relay_gateway.yaml
relay_gateway:
    stream_block_ms: 1000
    response_ttl_seconds: 60
    heartbeat_ttl_seconds: 30
    heartbeat_interval_seconds: 5
    auth_timeout_seconds: 60
```

All values are optional. Runtime command options can further tune connection limits and request timeouts.

## Host Wiring

Install the package from the local path repository during extraction work:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/relay-gateway-bundle",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "decent-newsroom/relay-gateway-bundle": "@dev"
    }
}
```

Register the bundle if Symfony Flex has not done so:

```php
use DecentNewsroom\RelayGatewayBundle\RelayGatewayBundle;

return [
    RelayGatewayBundle::class => ['all' => true],
];
```

The bundle expects a `Redis` service and the services exported by `decent-newsroom/nostr-client-bundle`.

The host should alias bundle contracts to adapters where local policy or diagnostics are needed:

```yaml
services:
    DecentNewsroom\RelayGatewayBundle\Contract\RelayUrlResolverInterface:
        alias: App\RelayGateway\RelayUrlResolverAdapter

    DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface:
        alias: App\RelayGateway\IdentityAuthChallengeSigner

    DecentNewsroom\RelayGatewayBundle\Contract\GatewayHealthRecorderInterface:
        alias: App\RelayGateway\GatewayHealthRecorderAdapter

    DecentNewsroom\RelayGatewayBundle\Contract\GatewayActivityRecorderInterface:
        alias: App\RelayGateway\GatewayActivityRecorderAdapter

    DecentNewsroom\RelayGatewayBundle\Contract\GatewayFilterStatsRecorderInterface:
        alias: App\RelayGateway\GatewayFilterStatsRecorderAdapter
```

## Running The Gateway

```bash
docker compose exec php bin/console app:relay-gateway
```

Useful options:

- `--time-limit=3600` - graceful restart interval, with `0` for unlimited.
- `--query-timeout=15` - default per-request query timeout.
- `--publish-timeout=10` - default per-request publish timeout.
- `--max-user-conns=5` - maximum open connections for one user pubkey.
- `--max-total-user-conns=200` - maximum open user-keyed connections.
- `--max-shared-conns=50` - maximum anonymous shared connections.
- `--user-idle-timeout=7200` - idle timeout for user-keyed connections.
- `--on-demand-idle-timeout=300` - idle timeout for on-demand shared connections.
- `--prewarm-shared-relays` - open configured shared relay connections at startup.
- `--auth-timeout=60` - NIP-42 AUTH roundtrip timeout.

## Client API

Inject `DecentNewsroom\RelayGatewayBundle\Service\RelayGatewayClient` into request-time services:

```php
$result = $gateway->query(
    relayUrls: ['wss://relay.example'],
    filters: ['kinds' => [30023], 'limit' => 20],
    pubkey: $userPubkeyHex,
    timeout: 15,
);

$publishResult = $gateway->publish(
    relayUrls: ['wss://relay.example'],
    signedEvent: $event,
    pubkey: $userPubkeyHex,
    timeout: 10,
);
```

The client also exposes:

- `warmUserConnections(string $pubkey, array $authRelayUrls)` for login warmup;
- `closeUserConnections(string $pubkey)` for logout cleanup;
- `isGatewayAlive()` for lightweight process checks.

When the gateway is disabled or Redis is unavailable, the host should fall back to its direct relay transport.

## NIP-42 AUTH

User-keyed sockets can answer relay AUTH challenges. The gateway asks `AuthChallengeSignerInterface` for a signed kind `22242` event using the user pubkey, relay URL, challenge, and timeout. The host can implement that with a server-side remote signer first, then a browser/Mercure fallback outside this bundle if desired.

Anonymous shared sockets do not sign AUTH challenges.

## Testing

This package currently relies on host-level validation:

```bash
docker compose exec php bin/console lint:container
docker compose exec php bin/phpunit
```

The deeper integration notes live in:

- `docs/relay-gateway-bundle.md`
- `../../documentation/Nostr/relay-gateway-service.md`
- `../../documentation/Nostr/user-relay-activity-log.md`
