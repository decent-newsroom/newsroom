# Essayist Membership Gateway

## Purpose

The Essayist relay (`strfry-essayist`) is a members-only Nostr relay for longform writing. Without a gateway layer, any client that knows the WebSocket URL can subscribe and read all articles — strfry's `write-policy.sh` plugin only fires on `EVENT` messages, not on `REQ`.

The `essayist-gateway` service sits between Caddy and `strfry-essayist`. It enforces NIP-42 AUTH on every inbound connection and checks active `ROLE_ESSAYIST_MEMBER` membership before forwarding anything to the relay. Once a connection is authenticated and approved, the gateway becomes a transparent bidirectional WebSocket proxy.

---

## Architecture

```
Internet ─WSS─▶  Caddy  (@essayistRelay)
                    │
                    ▼  plain WS (internal Docker network)
          ┌──────────────────────────────┐
          │  essayist-gateway            │  port 7780
          │                              │
          │  1. send AUTH challenge      │
          │  2. wait for AUTH msg        │
          │  3. verify kind:22242        │
          │  4. check membership         │◄── Redis (fast) / PHP API (fallback)
          │  5. proxy or reject          │
          └──────────────┬───────────────┘
                         │  plain WS (internal Docker network only)
                         ▼
          strfry-essayist:7779
```

`strfry-essayist` is not published on any host port in the `essayist` Docker profile — it is only reachable from `essayist-gateway` over the internal compose network. Caddy only routes to the gateway.

---

## NIP-42 Protocol Flow

```
[client connects]

gateway → client    ["AUTH", "<challenge>"]           random 32-byte hex, per-connection

  while waiting for AUTH (up to 30s):
    incoming REQ   → ["CLOSED",  <sub_id>,   "auth-required: membership required"]
    incoming EVENT → ["OK",      <event_id>, false, "auth-required: membership required"]

client → gateway   ["AUTH", <kind-22242-event>]

  gateway verifies:
    kind == 22242
    created_at within ±600 seconds of now
    tag ["challenge", <issued-challenge>] present and matching
    tag ["relay", <relay-url>] present; normalised URL matches RELAY_PUBLIC_URL
    Schnorr signature valid over event id

  membership check:
    1. Redis GET essayist_member:{pubkey_hex}   (TTL 600 s)
    2. on miss: GET http://php/api/internal/essayist/writer/{pubkey}
                    Authorization: Bearer $ESSAYIST_POLICY_TOKEN
       cache result in Redis for 600 s

  approved:
    gateway → client    ["OK", <event_id>, true, ""]
    open WS to strfry-essayist:7779
    replay any buffered pre-auth frames to upstream
    start bidirectional copy loop (transparent proxy)

  rejected (bad sig, wrong relay, expired):
    gateway → client    ["NOTICE", "restricted: authentication failed"]
    close connection

  rejected (not a member):
    gateway → client    ["NOTICE", "restricted: active Essayist membership required — decentnewsroom.com/essayist"]
    close connection after 5 s grace
```

### AUTH timeout

If no `AUTH` message is received within 30 seconds of the connection the gateway closes with a `NOTICE` explaining the requirement. The timeout is configurable via `AUTH_TIMEOUT_SECONDS`.

---

## Membership Check

The gateway uses a two-tier lookup to avoid hammering the PHP app on every incoming connection.

| Tier | Mechanism | TTL |
|---|---|---|
| Fast path | `Redis GET essayist_member:{pubkey_hex}` | 600 s |
| Slow path | `GET /api/internal/essayist/writer/{pubkey}` | result cached in Redis |

The Redis key holds `1` (approved) or `0` (rejected). A missing key always falls through to the slow path.

The PHP side can pre-warm the Redis cache whenever it grants or revokes `ROLE_ESSAYIST_MEMBER` — e.g. from `EssayistAdminController` and the CLI grant command — so the fast path is already populated before the first gateway lookup.

---

## NIP-11 Passthrough

HTTP `GET` requests to the relay URL with `Accept: application/nostr+json` are reverse-proxied directly to `strfry-essayist:7779` without requiring AUTH. This allows clients to read relay metadata (name, description, `auth_required: true`, `payment_required: false`) before initiating a WebSocket connection.

---

## Service Implementation

### Language: Go

The gateway is implemented as a standalone Go binary. Rationale:

- Single static binary, minimal container footprint.
- Goroutine-per-connection concurrency — suitable for long-lived WS proxy connections.
- `nbd-wtf/go-nostr` covers secp256k1 signature verification and event handling.
- No shared state with the PHP app beyond Redis and the internal HTTP policy API.
- The service can be extracted from this repository and reused as a generic membership wrapper for any strfry relay instance.

### Source layout

```
docker/essayist-gateway/
  Dockerfile        Two-stage build: golang:alpine → scratch/alpine
  go.mod
  go.sum
  cmd/gateway/
    main.go         Entry point; config loading; HTTP + WS listener
  internal/
    auth/
      challenge.go  Challenge generation (crypto/rand, hex)
      verify.go     kind:22242 verification (sig, relay, challenge, timestamp)
    membership/
      checker.go    MembershipChecker interface
      redis.go      Redis fast-path (go-redis)
      http.go       PHP API slow-path (net/http)
      cache.go      Two-tier implementation: Redis → HTTP → Redis write-back
    proxy/
      handler.go    WS upgrade, AUTH state machine, pre-auth frame buffer
      relay.go      Upstream WS dial + bidirectional copy
      nip11.go      NIP-11 HTTP passthrough
    config/
      config.go     Env-based config struct
```

### Key interfaces

```go
// MembershipChecker returns true if pubkey holds active ROLE_ESSAYIST_MEMBER.
type MembershipChecker interface {
    IsMember(ctx context.Context, pubkeyHex string) (bool, error)
}

// ChallengeStore holds per-connection issued challenges (in-memory, connection-scoped).
// No shared state needed — each connection owns its challenge.
type ConnectionState struct {
    Challenge   string
    IsAuthed    bool
    PubkeyHex   string
    Buffer      []Frame   // pre-auth frames (capped at MaxBufferFrames)
}
```

---

## Configuration (environment variables)

| Variable | Default | Description |
|---|---|---|
| `LISTEN_ADDR` | `:7780` | Address the gateway binds on |
| `UPSTREAM_RELAY_URL` | `ws://strfry-essayist:7779` | Internal WS URL of the relay |
| `RELAY_PUBLIC_URL` | `wss://essayist.decentnewsroom.com` | Canonical public URL; must match the `relay` tag in kind:22242 |
| `ESSAYIST_POLICY_TOKEN` | *(required)* | Bearer token for `/api/internal/essayist/writer/{pubkey}` |
| `PHP_APP_URL` | `http://php` | Internal base URL of the Symfony app |
| `REDIS_URL` | `redis://:password@redis:6379` | Redis connection string |
| `REDIS_MEMBER_KEY_PREFIX` | `essayist_member:` | Redis key prefix for membership cache |
| `REDIS_MEMBER_TTL_SECONDS` | `600` | How long a cached membership result is trusted |
| `AUTH_TIMEOUT_SECONDS` | `30` | Seconds to wait for client AUTH before closing |
| `HEALTH_ADDR` | `:7781` | Address for the `GET /health` endpoint |
| `MAX_BUFFER_FRAMES` | `10` | Pre-auth frames to buffer per connection |
| `LOG_LEVEL` | `info` | `debug` / `info` / `warn` / `error` |

---

## Docker Integration

### compose.yaml changes

```yaml
essayist-gateway:
  build:
    context: docker/essayist-gateway
    dockerfile: Dockerfile
  profiles: [essayist]
  restart: unless-stopped
  depends_on:
    php:
      condition: service_healthy
    strfry-essayist:
      condition: service_started
    redis:
      condition: service_started
  environment:
    UPSTREAM_RELAY_URL: ws://strfry-essayist:7779
    RELAY_PUBLIC_URL: ${ESSAYIST_RELAY_PUBLIC_URL:-wss://essayist.decentnewsroom.com}
    ESSAYIST_POLICY_TOKEN: ${ESSAYIST_POLICY_TOKEN:-changeme}
    PHP_APP_URL: http://php
    REDIS_URL: redis://:${REDIS_PASSWORD:-r_password}@redis:6379
  healthcheck:
    test: ["CMD", "wget", "-qO-", "http://localhost:7781/health"]
    interval: 30s
    timeout: 5s
    retries: 3
```

`strfry-essayist:7779` is **not** mapped to a host port when the `essayist` profile is active — the relay is only reachable from within the compose network. Remove the `ports` mapping from `strfry-essayist` in production.

### Caddy change

```
@essayistRelay host {$ESSAYIST_RELAY_DOMAIN:essayist.localhost}
handle @essayistRelay {
    encode zstd gzip
    reverse_proxy essayist-gateway:7780   # was: strfry-essayist:7779
}
```

---

## write-policy.sh after the gateway

With the gateway enforcing membership at the connection level (both reads and writes), `write-policy.sh` no longer needs to call the PHP membership API. It is reduced to a **kind-only filter** as a defence-in-depth layer:

```bash
#!/bin/bash
# Kind-only filter — membership is enforced by essayist-gateway upstream.
# This script rejects any event kind other than 30023 (longform article).
while IFS= read -r line; do
    KIND=$(printf '%s' "$line" | jq -r '.event.kind // empty')
    if [[ "$KIND" == "30023" ]]; then
        printf '{"action":"accept"}\n'
    else
        printf '{"action":"reject","msg":"only kind 30023 accepted on this relay"}\n'
    fi
done
```

The curl dependency and bearer-token secret are removed from the relay container entirely.

---

## Pre-auth Frame Buffer

Some clients send a `REQ` immediately after opening a WebSocket before they receive or process the `AUTH` challenge. The gateway buffers up to `MAX_BUFFER_FRAMES` (default 10) incoming frames per connection during the auth window. On successful auth, buffered frames are replayed to the upstream relay. On auth failure or timeout, the buffer is discarded.

This avoids confusing clients that expect their first subscription to be answered even though they must authenticate first.

---

## Observability

All log lines are structured JSON with the following fields where applicable:

| Field | Description |
|---|---|
| `conn_id` | Random UUID per connection |
| `pubkey` | First 8 hex chars of authenticated pubkey |
| `remote` | Client IP address |
| `outcome` | `authed`, `rejected_sig`, `rejected_relay`, `rejected_expired`, `rejected_membership`, `timeout` |
| `latency_ms` | Time from connect to AUTH completion |
| `upstream_latency_ms` | Time to dial strfry upstream |

Example log lines:

```json
{"level":"info","conn_id":"a1b2c3d4","remote":"1.2.3.4:51234","msg":"AUTH challenge sent"}
{"level":"info","conn_id":"a1b2c3d4","pubkey":"d475ce4b","outcome":"authed","latency_ms":312,"msg":"AUTH accepted, proxying"}
{"level":"warn","conn_id":"e5f6a7b8","pubkey":"deadbeef","outcome":"rejected_membership","latency_ms":89,"msg":"membership check failed"}
{"level":"warn","conn_id":"c9d0e1f2","outcome":"timeout","msg":"AUTH timeout, closing"}
```

---

## Security Notes

- The gateway is the **sole** NIP-42 enforcement point for both REQ (reads) and EVENT (writes).
- `strfry-essayist` is only reachable inside the Docker compose network; it does not listen on any host-mapped port.
- The `ESSAYIST_POLICY_TOKEN` bearer secret is shared between the gateway and the PHP app; it is not exposed to relay clients.
- Challenge strings are 32 bytes of cryptographically random data (Go `crypto/rand`), scoped to a single connection and discarded after use.
- Replay attack window is bounded by the ±600 second `created_at` tolerance on kind:22242.
- Redis membership cache is advisory; a cache miss always falls through to the authoritative PHP API. Cache entries are short-lived (10 min) so revocations propagate promptly.

---

## Extraction as a Generic Membership Wrapper

The gateway is designed to be repositionable as a standalone open-source tool. To reuse it for a different relay:

1. Replace the `MembershipChecker` implementation with your own (the interface is a single method).
2. Set `UPSTREAM_RELAY_URL` and `RELAY_PUBLIC_URL` to point at the target relay.
3. Adjust `ESSAYIST_POLICY_TOKEN` / `PHP_APP_URL` or swap in a different HTTP membership backend.
4. The NIP-42 verification and proxy layers require no changes.

The only Essayist-specific logic is in `membership/http.go` (the URL shape of the PHP API endpoint). Everything else is relay-agnostic.

---

## Open Items

| Item | Status |
|---|---|
| Remove `ports: 7779:7779` from `strfry-essayist` in production compose | Planned |
| PHP-side Redis pre-warming on role grant/revoke | Planned |
| Integration test: connect without AUTH → expect CLOSED/OK reject | Planned |
| Integration test: AUTH with invalid sig → expect NOTICE + close | Planned |
| Integration test: AUTH with non-member pubkey → expect NOTICE + close | Planned |
| Integration test: AUTH with valid member → expect proxied REQ/EVENT | Planned |
| `ROLE_ESSAYIST_MEMBER` expiry cron → Redis invalidation | Planned (cron command) |

