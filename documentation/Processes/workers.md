# Workers & Event-Driven Processing

## Consolidated Worker (`app:run-workers`)

The `app:run-workers` command manages all background processes in a single supervisor. Each subprocess auto-restarts on failure.

### Workers

| Worker | Command | Purpose |
|--------|---------|---------|
| Messenger | `messenger:consume` | Async job queue (Redis transport) |
| Articles | `app:subscribe-local-relay` | Subscribe to local relay for articles (kind 30023) |
| Media | `app:subscribe-local-media` | Subscribe to local relay for media (kinds 20, 21, 22) |
| Magazines | `app:subscribe-local-magazines` | Subscribe to local relay for magazine indices (kind 30040) |
| Profiles | `app:profile-refresh-worker` | Periodic profile metadata updates |
| Gateway | `app:relay-gateway` | Persistent relay connections (if `RELAY_GATEWAY_ENABLED=true`) |

### Usage

```bash
# Run all workers
docker compose exec php bin/console app:run-workers

# Custom profile settings
docker compose exec php bin/console app:run-workers --profile-interval=600 --profile-batch-size=100

# Disable specific workers
docker compose exec php bin/console app:run-workers --without-media --without-profiles
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--without-articles` | false | Disable article hydration |
| `--without-media` | false | Disable media hydration |
| `--without-magazines` | false | Disable magazine hydration |
| `--without-profiles` | false | Disable profile refresh |
| `--without-messenger` | false | Disable messenger consumer |
| `--without-gateway` | false | Disable relay gateway |
| `--profile-interval` | 600 | Profile refresh interval (seconds) |
| `--profile-batch-size` | 100 | Profiles per refresh batch |

## Event-Driven Article Processing

Article post-processing (QA, indexing, marking as indexed) runs via the `articles:post-process` command, triggered by cron every 5 minutes and also available manually:

```bash
docker compose exec php bin/console articles:post-process
docker compose exec php bin/console articles:post-process --skip-qa --skip-index
```

## Kind 30040 Workers

Magazine index events (kind 30040) are ingested via the local relay subscription, then projected to `Magazine` entities via async `ProjectMagazineMessage` messages. The projection cron runs every 10 minutes.

## Lessons Learned

- **Worker interference**: Each subscription worker (`subscribe-local-relay`, `subscribe-local-media`, `subscribe-local-magazines`) must use distinct subscription IDs to avoid interfering with each other on the same relay connection.
- **EntityManager closed**: Long-running workers can encounter "EntityManager is closed" after a DB error. The `RetryOnConnectionLostMiddleware` handles stale connections, but workers should also catch and recover from closed EntityManager states.

