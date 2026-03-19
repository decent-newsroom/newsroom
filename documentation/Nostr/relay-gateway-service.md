# Relay Gateway Service

The Relay Gateway (`relay-gateway`) is a dedicated Docker service that maintains persistent WebSocket connections to external Nostr relays. It handles NIP-42 AUTH challenges via a Mercure roundtrip to the user's browser signer, and serves as the single point of relay communication for all FrankenPHP request workers.

## Architecture

```
┌──────────────┐       Redis Streams        ┌──────────────────┐      WebSocket      ┌───────────┐
│  PHP workers │  ──  relay:requests  ──▶   │  relay-gateway   │  ◀──────────────▶   │  Relays   │
│  (FrankenPHP)│  ◀─  relay:responses:{id}  │  (app:relay-gw)  │                     │  (wss://) │
└──────────────┘                             └────────┬─────────┘                     └───────────┘
                                                      │
                                                Mercure (AUTH)
                                                      │
                                               ┌──────▼──────┐
                                               │   Browser   │
                                               │  (NIP-07)   │
                                               └─────────────┘
```

### Communication Protocol

| Redis Stream | Direction | Purpose |
|---|---|---|
| `relay:requests` | PHP → Gateway | Query and publish requests from request workers |
| `relay:control` | PHP → Gateway | Lifecycle commands: `warm` (pre-open connections), `close` |
| `relay:responses:{id}` | Gateway → PHP | Per-correlation-ID response streams with events/errors |

### Connection Model (On-Demand)

All connections are opened lazily when a query or publish first targets a relay, then kept alive for an idle TTL before being closed. No persistent connections are held at startup.

| Type | Key | Default Idle TTL | Description |
|---|---|---|---|
| On-demand shared | relay URL | 5 min | Opened when needed, shared across requests |
| User-specific | relay::pubkey | 30 min | Opened via `warm` command, authenticated as user's npub |

### NIP-42 AUTH Flow

1. Relay sends `AUTH` challenge to the gateway over WebSocket
2. Gateway publishes challenge to `relay-auth/{pubkey}` Mercure topic
3. Browser's `relay_auth_controller.js` receives challenge, signs kind-22242 event via NIP-07
4. Signed event stored in Redis (`relay_auth_signed:{requestId}`)
5. Gateway polls Redis, finds the signed event, sends it to the relay
6. Connection is now authenticated — deferred REQs/publishes are replayed

## Enabling the Gateway

### Development

```bash
# Start all services including the gateway
docker compose --profile gateway up -d

# Or set COMPOSE_PROFILES in your .env
echo "COMPOSE_PROFILES=gateway" >> .env
docker compose up -d
```

Ensure the `php` service has `RELAY_GATEWAY_ENABLED=true` so the application routes queries through `RelayGatewayClient` instead of direct WebSocket connections:

```bash
# In .env or compose.override.yaml
RELAY_GATEWAY_ENABLED=true
```

### Production

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local --profile gateway up -d
```

Or add to `.env.prod.local`:
```
COMPOSE_PROFILES=gateway
RELAY_GATEWAY_ENABLED=true
```

## Configuration Options

The `app:relay-gateway` command accepts the following options:

| Option | Default | Description |
|---|---|---|
| `--max-user-conns` | 5 | Maximum connections per user |
| `--max-total-user-conns` | 200 | Maximum total user connections across all users |
| `--max-shared-conns` | 20 | Maximum on-demand shared connections |
| `--user-idle-timeout` | 1800 | User connection idle timeout in seconds (30 min) |
| `--on-demand-idle-timeout` | 300 | On-demand shared connection idle timeout in seconds (5 min) |
| `--auth-timeout` | 60 | AUTH roundtrip timeout in seconds |
| `--time-limit` | 3600 | Max runtime before graceful restart (0=unlimited) |

To customize, edit the `command` in `compose.yaml`:

```yaml
relay-gateway:
    command:
        - php
        - bin/console
        - app:relay-gateway
        - -vv
        - --time-limit=3600
        - --max-total-user-conns=500
        - --on-demand-idle-timeout=600
```

## Monitoring

### Status Command

```bash
docker compose exec relay-gateway php bin/console app:relay-gateway:status
```

The status command shows:
- **Gateway Process** — heartbeat check (alive/stale/missing), updated every 60s by the gateway's maintenance loop
- **Redis Streams** — request and control stream lengths and last IDs
- **Open Response Streams** — in-flight query/publish responses (transient, 60s TTL — "None" is normal when no queries are active)
- **Health Store** — per-relay health metrics including auth status, failures, latency, and last activity timestamps

### Logs

```bash
docker compose logs -f relay-gateway
```

### Health Tracking

The gateway integrates with `RelayHealthStore` (Redis-backed) to track per-relay health metrics:
- Success/failure counts
- Latency measurements
- Connection state

Health data is available via the admin relay dashboard.

## Integration with Other Services

| Service | Relationship |
|---|---|
| `RelayGatewayClient` | PHP-side client that writes to `relay:requests` and reads from `relay:responses:{id}` |
| `RelayRegistry` | Provides relay URLs; gateway resolves internal URLs (e.g., `ws://strfry:7777`) for Docker networking |
| `RelayHealthStore` | Gateway reports connection health metrics (latency, errors) |
| `NostrRelayPool` | Routes queries through `RelayGatewayClient` when `RELAY_GATEWAY_ENABLED=true` |
| Mercure (`php` service) | Gateway publishes AUTH challenges for browser signing |

## Migration from Worker Subprocess

Previously, the relay gateway ran as a subprocess of `app:run-workers` (the `worker` Docker service), gated by the `--without-gateway` flag and `RELAY_GATEWAY_ENABLED` env var. It has been extracted into its own service for:

- **Independent scaling** — gateway resource usage is decoupled from Messenger consumers and hydration workers
- **Independent restarts** — gateway restarts (e.g., after `--time-limit`) don't disrupt article/media hydration
- **Better resource isolation** — memory and CPU limits can be tuned independently
- **Cleaner logs** — gateway logs are isolated in their own container

