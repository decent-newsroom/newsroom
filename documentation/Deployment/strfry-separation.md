# Strfry Relay — Separated Deployment

The strfry Nostr relay runs in its own Docker Compose project (`compose.strfry.yaml`),
independent from the main application services. This means you can rebuild and
redeploy the app (php, worker, cron, database) **without restarting the relay**,
and vice versa.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                   Docker network: "newsroom"                │
│                                                             │
│  ┌──────────────────────────┐  ┌──────────────────────────┐ │
│  │  compose.strfry.yaml     │  │  compose.yaml (+override │ │
│  │                          │  │   or +compose.prod.yaml) │ │
│  │  ┌────────┐              │  │                          │ │
│  │  │ strfry │ :7777        │  │  ┌─────┐ ┌────────┐     │ │
│  │  └────────┘              │  │  │ php │ │database│     │ │
│  │  volume: strfry_data     │  │  └─────┘ └────────┘     │ │
│  │                          │  │  ┌────────┐ ┌──────┐    │ │
│  │  Creates the network ──────►│  │ worker │ │ cron │    │ │
│  │                          │  │  └────────┘ └──────┘    │ │
│  │                          │  │  ┌───────┐              │ │
│  │                          │  │  │ redis │ (dev only)   │ │
│  │                          │  │  └───────┘              │ │
│  └──────────────────────────┘  └──────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

**Key points:**

- `compose.strfry.yaml` creates and owns the shared `newsroom` bridge network.
- `compose.yaml` joins the same network via `networks.default` → `external: true`.
- App services reach the relay at `ws://strfry:7777` (unchanged).
- Each compose project has its own lifecycle — `docker compose up -d` for the app
  never touches the strfry container.

## Compose files

| File | Purpose |
|------|---------|
| `compose.strfry.yaml` | Strfry relay (standalone, owns the shared network) |
| `compose.yaml` | Base app services (php, database, cron, worker) |
| `compose.override.yaml` | Dev overrides (bind mounts, xdebug, redis) |
| `compose.prod.yaml` | Production overrides (optimised images, TLS, healthchecks) |

## Startup order

The strfry compose file **must** be started first because it creates the
`newsroom` Docker network that the app services join.

### Local development

```bash
# 1. Start strfry (creates the network + starts relay)
docker compose -f compose.strfry.yaml up -d

# 2. Start the app (auto-loads compose.override.yaml)
docker compose up -d
```

### Production

```bash
# 1. Start strfry
docker compose -f compose.strfry.yaml --env-file .env.prod.local up -d

# 2. Start the app
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d
```

## Redeploying the app (without touching strfry)

This is the main benefit. Pull new code, rebuild, and restart — the relay keeps
running the entire time with zero downtime on its side.

```bash
git pull

# Production
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d --build

# Development
docker compose up -d --build
```

Strfry is **not** affected by these commands.

## Updating strfry (only when needed)

Only run this when you actually want to update the relay image or change its config:

```bash
# Pull the latest image
docker compose -f compose.strfry.yaml pull

# Recreate with the new image
docker compose -f compose.strfry.yaml up -d
```

The `strfry_data` volume persists across recreations — no data is lost.

## Make targets

All `relay-*` Makefile targets use `compose.strfry.yaml` automatically:

```bash
make relay-up          # Start relay
make relay-down        # Stop relay
make relay-build       # Pull latest image
make relay-logs        # Tail logs
make relay-shell       # Shell into container
make relay-stats       # Show database stats
make relay-export      # Export to .jsonl backup
make relay-import FILE=backup.jsonl   # Import from backup
make relay-prime       # Initial backfill
make relay-ingest-now  # Trigger manual sync
make relay-test        # PHP smoke test
```

## Migrating from the old single-compose setup

If you already have a running strfry container managed by the old `compose.yaml`
(before this change), follow these steps to migrate without losing data:

```bash
# 1. Back up relay data (safety net)
make relay-export

# 2. Stop only the app services — strfry keeps running
docker compose stop php worker cron

# 3. Start strfry under the new compose file
#    This creates the "newsroom" network and adopts the existing strfry_data volume.
docker compose -f compose.strfry.yaml up -d

# 4. Tear down the old project (the volume persists!)
docker compose down
#    ⚠️  Do NOT use -v — that deletes volumes and you lose relay data.

# 5. Start the app on the new setup
docker compose up -d           # dev
# or for production:
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local up -d
```

**Why is the data safe?**

- Relay data lives in the `strfry_data` Docker named volume.
- `docker compose down` (without `-v`) removes containers but **never** removes
  named volumes.
- The new `compose.strfry.yaml` declares the same volume name, so Docker
  reattaches the existing volume automatically.

**The only commands that would delete your data:**

| Command | Deletes data? |
|---------|---------------|
| `docker compose down` | ❌ No — volumes preserved |
| `docker compose down -v` | ✅ **Yes** — deletes all named volumes |
| `docker volume rm strfry_data` | ✅ **Yes** — deletes the specific volume |

## Stopping everything

```bash
# Stop app services
docker compose down

# Stop strfry
docker compose -f compose.strfry.yaml down

# Stop both (order doesn't matter for shutdown)
docker compose down ; docker compose -f compose.strfry.yaml down
```

## Troubleshooting

### "network newsroom declared as external but could not be found"

Strfry isn't running yet. Start it first:

```bash
docker compose -f compose.strfry.yaml up -d
```

### App can't reach strfry (connection refused to ws://strfry:7777)

1. Check strfry is running:
   ```bash
   docker compose -f compose.strfry.yaml ps
   ```
2. Check both projects are on the same network:
   ```bash
   docker network inspect newsroom
   ```
   You should see containers from both compose projects listed.

### Volume not reattached after migration

Verify the volume exists:
```bash
docker volume ls | grep strfry
```

If the old volume has a project-name prefix (e.g. `newsroom_strfry_data` instead
of `strfry_data`), you can copy it:

```bash
# Export from old volume
docker run --rm -v newsroom_strfry_data:/data -v $(pwd):/backup alpine \
  tar czf /backup/strfry-data.tar.gz -C /data .

# Import into new volume
docker volume create strfry_data
docker run --rm -v strfry_data:/data -v $(pwd):/backup alpine \
  sh -c "cd /data && tar xzf /backup/strfry-data.tar.gz"
```

