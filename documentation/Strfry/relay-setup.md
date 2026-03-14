# Strfry Relay Setup & Configuration

## Overview

The project includes a private read-only strfry Nostr relay that acts as a local cache for long-form articles and related events. This improves performance and reduces dependency on public relays.

### Architecture

```
┌──────────┐     wss://relay.domain
│  Client  │────────────────────┐
└──────────┘                    │
                                ▼
┌──────────┐              ┌──────────┐
│  Caddy   │◄─────────────│  strfry  │ (read-only cache)
│  (proxy) │              │  relay   │
└──────────┘              └────┬─────┘
                               │  + router
                               ▼
                           LMDB volume
```

### Docker Services

- **strfry**: Runs both `strfry relay` and `strfry router` in the same container
- Configuration: `docker/strfry/strfry.conf`, `docker/strfry/router.conf`
- Write policy: `docker/strfry/write-policy.sh` (controls which events are accepted)
- Data stored in `strfry_data` Docker volume

### Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `RELAY_DOMAIN` | `relay.localhost` | Public WebSocket domain |
| `NOSTR_DEFAULT_RELAY` | `ws://strfry:7777` | Internal relay URL used by Symfony |

### Event Kinds Ingested

| Kind | Description |
|------|-------------|
| 30023 | Long-form articles (NIP-23) |
| 30024 | Drafts (NIP-23) |
| 30040, 30041 | Publications (NKBIP-01) |
| 20, 21, 22 | Media events (NIP-68/71) |
| 0 | Profiles |
| 7 | Reactions |
| 9735 | Zap receipts |
| 9802 | Highlights |
| 1111 | Comments |
| 10002 | Relay lists |
| 39089 | Follow packs |

### Makefile Targets

```bash
make relay-build     # Build relay containers (~10 min first time)
make relay-up        # Start strfry + ingest services
make relay-down      # Stop relay services
make relay-shell     # Shell into strfry container
make relay-test      # Run PHP smoke test
make relay-stats     # Show relay statistics
make relay-export    # Export relay database to JSONL
make relay-import FILE=backup.jsonl  # Import events from file
```

### Local Development

No DNS needed locally — relay works out-of-the-box:
- From inside Docker: `ws://strfry:7777` (used by Symfony)
- From host machine: `ws://localhost:7777` (for testing)

### DNS for Production

Add an A/AAAA record for `relay.yourdomain.com` pointing to the same server as the main app. Caddy handles TLS automatically.

## Lessons Learned

- **Registry access**: Images are built locally from Dockerfiles rather than pulled from registries (avoids `ghcr.io` access issues).
- **Write policy**: The relay denies all direct client writes — events are only ingested via the router/sync process.

