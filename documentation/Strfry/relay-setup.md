# Strfry Relay Setup

The local strfry relay caches Nostr events and accepts client writes subject to its write policy and event limits. A router pulls configured upstream feeds; application subscription workers project relay events into PostgreSQL.

## Deployment

The relay is a service in `compose.yaml`, alongside the application. There is no separate `compose.strfry.yaml` deployment. The service uses `dockurr/strfry:latest`, starts both the relay and router, and stores LMDB data in the `strfry_data` volume mounted at `/var/lib/strfry`.

| File | Purpose |
|---|---|
| `docker/strfry/strfry.conf` | Relay limits, LMDB configuration, listener and write-policy integration |
| `docker/strfry/router.conf` | Upstream subscriptions and event-kind filters |
| `docker/strfry/write-policy.sh` | Accept incoming events except blocked pubkeys |
| `docker/strfry/blocked-pubkeys.txt` | Operator-maintained pubkey blocklist |
| `compose.yaml` | Service, image, mounts and relay/router startup |
| `frankenphp/Caddyfile` | Public proxy configuration |

`NOSTR_DEFAULT_RELAY` defaults to `ws://strfry:7777` for server-side access. `RELAY_DOMAIN` configures the public hostname. Internal Docker URLs must not be shown as browser relay addresses; [Relay Pool Management](relay-pool.md) explains LOCAL/PROJECT URL handling.

## Operation

```bash
docker compose up -d strfry worker-relay
docker compose logs --tail=100 strfry worker-relay
docker compose exec strfry strfry db-stats
docker compose stop worker-relay strfry
```

Equivalent Makefile targets are `relay-up`, `relay-down`, `relay-shell`, `relay-logs`, `relay-stats`, `relay-export`, and `relay-import FILE=backup.jsonl`. The gateway has separate `relay-gateway-up` and `relay-gateway-down` targets.

To redeploy application services without recreating the relay, explicitly name the application services in the Compose operation. See [Production Deployment Validation](../Admin/deployment-validation.md). Do not tear down volumes as part of an application-only redeploy.

## Ingestion and write policy

Router filters and application subscription kinds are separate controls. The former determine what upstream data enters the local relay; the latter determine what the host projects. Check `router.conf`, `RunRelayWorkersCommand`, and the individual subscription commands when adding a kind. See [Workers](../Processes/workers.md).

The default write policy accepts events unless the author's hex pubkey is in the mounted blocklist. Relay limits still apply. Updating the blocklist prevents future admission; it does not remove already-stored relay events or PostgreSQL projections.

The optional Essayist relay has its own service, policy, gateway, and volume. It must not be treated as the default relay.

See [Storage Maintenance](storage-maintenance.md) for backups and cleanup boundaries, and [Article Backfill](article-backfill.md) for importing missing local-relay articles into application projections.
