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

## Trusted-Publisher Sidestep (server-side broadcasts)

The gateway exists to authenticate **unknown** WebSocket clients. The Symfony app itself is not an unknown client — it already authenticated the user via the session and knows whether they hold `ROLE_ESSAYIST_MEMBER`. Forcing the PHP container to perform a NIP-42 handshake against the gateway for its own server-side publishes would require the app to either sign AUTH events as the user (which needs an active NIP-46 session) or fall back to an ephemeral key (which would fail the gateway's membership check anyway).

To avoid that, server-side broadcasts to the Essayist relay from logged-in members publish **directly** to the internal Docker URL (`ESSAYIST_RELAY_INTERNAL_URL`, default `ws://strfry-essayist:7779`), bypassing the gateway entirely. This is implemented in `App\Controller\Api\ArticleBroadcastController`:

1. The "Broadcast to Essayist" action sends the public Essayist WSS URL in the request payload.
2. Before publishing, the controller checks `getUser()->getRoles()` for `ROLE_ESSAYIST_MEMBER`.
3. If the role is present and the configured public URL matches the requested relay (scheme/host/port normalised), the URL is rewritten to the internal one.
4. `NostrClient::publishEvent` then opens a plain WebSocket to `strfry-essayist:7779` on the compose network and publishes the EVENT — no AUTH challenge is sent because the relay-side `write-policy.sh` is reduced to a kind-only filter (it accepts kind 30023 from any source).

Security model:

- The internal URL is unreachable from outside the compose network (`strfry-essayist` has no host port mapping), so the sidestep is only available to processes that are already inside the trust boundary.
- The strfry-side `write-policy.sh` still enforces `kind == 30023`, so a compromised PHP service cannot push arbitrary events onto the relay.
- Anonymous and non-member clients still go through the public WSS endpoint and are still subject to full gateway AUTH + membership checks.

## Original NIP-42 Protocol Flow



```
[client connects]

gateway → client    ["AUTH", "<challenge>"]           32 random bytes → 64 hex chars, per-connection

  while waiting for AUTH (up to AUTH_TIMEOUT_SECONDS, default 10s):
    incoming REQ   → ["CLOSED",  <sub_id>,   "auth-required: membership required"]
    incoming EVENT → ["OK",      <event_id>, false, "auth-required: membership required"]
    (frames buffered silently — see "Pre-auth Frame Buffer" below)

client → gateway   ["AUTH", <kind-22242-event>]

  gateway verifies:
    kind == 22242
    created_at within ±60 seconds of now
    tag ["challenge", <issued-challenge>] present and matching
    tag ["relay", <relay-url>] present; normalised URL matches RELAY_PUBLIC_URL
      (normalisation: lowercase scheme + host, strip default ports,
       strip trailing slash, reject path/query/fragment)
    Schnorr signature valid over event id

  membership check:
    1. Redis GET essayist_member:{pubkey_hex}   (TTL 600 s)
    2. on miss: GET {POLICY_URL_TEMPLATE with {pubkey} substituted}
                    Authorization: Bearer $ESSAYIST_POLICY_TOKEN
                    HTTP client timeout: 3 s (POLICY_HTTP_TIMEOUT_SECONDS)
       cache approvals in Redis for REDIS_MEMBER_TTL_SECONDS (default 600 s)
       cache rejections in Redis for REDIS_MEMBER_NEG_TTL_SECONDS (default 30 s)

  approved:
    gateway → client    ["OK", <event_id>, true, ""]
    open WS to strfry-essayist:7779
    replay any buffered pre-auth frames to upstream
    start bidirectional copy loop (transparent proxy)

  rejected (bad sig, wrong relay, expired):
    gateway → client    ["CLOSED", "*", "restricted: authentication failed"]
    gateway → client    ["NOTICE", "restricted: authentication failed"]
    close connection immediately after flush

  rejected (not a member):
    gateway → client    ["CLOSED", "*", "restricted: active Essayist membership required"]
    gateway → client    ["NOTICE", "restricted: active Essayist membership required — decentnewsroom.com/essayist"]
    close connection immediately after flush
```

Machine-readable signal is the `restricted:` / `auth-required:` prefix on `CLOSED` / `OK` frames per NIP-42. The `NOTICE` carries the human-readable supplement only.

### AUTH timeout

If no `AUTH` message is received within `AUTH_TIMEOUT_SECONDS` (default `10`) of the connection the gateway sends a final `["NOTICE", "auth-required: AUTH timeout"]` and closes.

### Redis failure mode

If Redis is unreachable, the gateway skips the fast path and goes straight to the PHP slow path on every connection (fail-degraded, not fail-open and not fail-closed). A circuit breaker suppresses repeated Redis dials for `REDIS_BREAKER_COOLDOWN_SECONDS` (default `30`) after consecutive failures.

### Revocation

Revocation is push-based. The PHP app, on role grant or revoke:

1. Writes / deletes the `essayist_member:{pubkey_hex}` Redis key.
2. `PUBLISH essayist_member_revoked {pubkey_hex}` on Redis pub/sub.

The gateway subscribes to `essayist_member_revoked` and closes any currently-authenticated connections whose pubkey matches. TTL-based expiry remains the safety net but is not the primary propagation path.

---

## Membership Check

The gateway uses a two-tier lookup to avoid hammering the PHP app on every incoming connection.

| Tier | Mechanism | TTL |
|---|---|---|
| Fast path | `Redis GET essayist_member:{pubkey_hex}` | `REDIS_MEMBER_TTL_SECONDS` (approvals) / `REDIS_MEMBER_NEG_TTL_SECONDS` (rejections) |
| Slow path | `GET {POLICY_URL_TEMPLATE}` with 3 s timeout | result cached in Redis |

The Redis key holds `1` (approved) or `0` (rejected). A missing key always falls through to the slow path. Approvals are cached longer than rejections so that newly granted members are not held out by stale negative entries — push-based revocation (above) handles invalidation in the other direction.

The PHP side pre-warms the Redis cache whenever it grants or revokes `ROLE_ESSAYIST_MEMBER` — e.g. from `EssayistAdminController` and the CLI grant command — so the fast path is already populated before the first gateway lookup.

---

## NIP-11 Passthrough

HTTP `GET /` to the relay URL with `Accept: application/nostr+json` is reverse-proxied directly to `strfry-essayist:7779` without requiring AUTH. Any other path, method, or `Accept` header returns `404`. This allows clients to read relay metadata (name, description, `auth_required: true`, `payment_required: true`, `payments_url`) before initiating a WebSocket connection without exposing the upstream relay's broader HTTP surface.

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
| `ESSAYIST_POLICY_TOKEN` | *(required, no default)* | Bearer token for the membership policy endpoint |
| `POLICY_URL_TEMPLATE` | `http://php/api/internal/essayist/writer/{pubkey}` | Membership policy URL; `{pubkey}` is replaced with the hex pubkey |
| `POLICY_HTTP_TIMEOUT_SECONDS` | `3` | HTTP client timeout for the slow-path membership lookup |
| `REDIS_URL` | *(required, no default)* | Redis connection string |
| `REDIS_MEMBER_KEY_PREFIX` | `essayist_member:` | Redis key prefix for membership cache |
| `REDIS_MEMBER_TTL_SECONDS` | `600` | TTL for cached **approvals** |
| `REDIS_MEMBER_NEG_TTL_SECONDS` | `30` | TTL for cached **rejections** (short, so grants propagate fast) |
| `REDIS_BREAKER_COOLDOWN_SECONDS` | `30` | Cooldown after consecutive Redis failures before retrying the fast path |
| `REVOCATION_CHANNEL` | `essayist_member_revoked` | Redis pub/sub channel for push-based revocation |
| `AUTH_TIMEOUT_SECONDS` | `10` | Seconds to wait for client AUTH before closing |
| `CREATED_AT_TOLERANCE_SECONDS` | `60` | Allowed clock skew on kind:22242 `created_at` |
| `MAX_CONNECTIONS` | `2000` | Global cap on concurrent WS connections |
| `MAX_CONNECTIONS_PER_IP` | `20` | Per-IP cap on concurrent WS connections |
| `MAX_PREAUTH_FRAME_BYTES` | `32768` | Max size of a single frame accepted before AUTH completes |
| `MAX_BUFFER_FRAMES` | `10` | Pre-auth frames to buffer per connection |
| `HEALTH_ADDR` | `:7781` | Address for the `GET /health` endpoint |
| `METRICS_ADDR` | `:7782` | Address for the Prometheus `GET /metrics` endpoint |
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
    ESSAYIST_POLICY_TOKEN: ${ESSAYIST_POLICY_TOKEN:?ESSAYIST_POLICY_TOKEN must be set}
    POLICY_URL_TEMPLATE: http://php/api/internal/essayist/writer/{pubkey}
    REDIS_URL: redis://:${REDIS_PASSWORD:?REDIS_PASSWORD must be set}@redis:6379
  healthcheck:
    test: ["CMD", "wget", "-qO-", "http://localhost:7781/health"]
    interval: 30s
    timeout: 5s
    retries: 3
```

Secrets (`ESSAYIST_POLICY_TOKEN`, `REDIS_PASSWORD`) are declared with `${VAR:?...}` so the container fails to start when they are missing — no `:-changeme` fallbacks.

`strfry-essayist:7779` is **not** mapped to a host port when the `essayist` profile is active — the relay is only reachable from within the compose network. Remove the `ports` mapping from `strfry-essayist` in production.

### Caddy change

```
@essayistRelay host {$ESSAYIST_RELAY_DOMAIN:essayist.localhost}
handle @essayistRelay {
    encode zstd gzip
    reverse_proxy essayist-gateway:7780   # was: strfry-essayist:7779
}
```

Caddy's `reverse_proxy` auto-upgrades WebSocket connections; no additional `header_up Connection` / `Upgrade` directives are required.

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

Some clients send a `REQ` immediately after opening a WebSocket before they receive or process the `AUTH` challenge. The gateway silently buffers up to `MAX_BUFFER_FRAMES` (default 10) incoming frames per connection during the auth window — each frame is also subject to `MAX_PREAUTH_FRAME_BYTES`. Buffered frames are **not** answered with `CLOSED` / `OK` during the wait; they are held. On successful auth they are replayed to the upstream relay. On auth failure or timeout the buffer is discarded.

This avoids confusing clients that expect their first subscription to be answered even though they must authenticate first, while bounding pre-auth memory use.

The `auth-required:` `CLOSED` / `OK` responses described in the protocol flow are sent only for frames that **exceed** the buffer cap (or arrive after auth has already failed) — i.e. when the gateway has chosen not to hold them.

---

## Health & Metrics

### `GET /health` (`HEALTH_ADDR`)

Returns `200 OK` only when:

- the process is running,
- a TCP dial to `UPSTREAM_RELAY_URL` succeeds within 1 s, and
- a `PING` to Redis succeeds within 500 ms (when Redis breaker is closed).

Otherwise returns `503` with a short JSON body indicating which dependency failed. Suitable for Docker / Kubernetes liveness + readiness probes.

### `GET /metrics` (`METRICS_ADDR`)

Prometheus exposition. Counters and gauges:

| Metric | Type | Labels |
|---|---|---|
| `gateway_auth_total` | counter | `outcome` (`authed`, `rejected_sig`, `rejected_relay`, `rejected_expired`, `rejected_membership`, `timeout`) |
| `gateway_active_connections` | gauge | — |
| `gateway_membership_cache_total` | counter | `result` (`hit_approved`, `hit_rejected`, `miss`, `error`) |
| `gateway_policy_request_seconds` | histogram | `outcome` (`ok`, `timeout`, `error`) |
| `gateway_upstream_dial_failures_total` | counter | — |
| `gateway_preauth_buffer_overflow_total` | counter | — |
| `gateway_revocation_close_total` | counter | — |

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
- Challenge strings are 32 bytes of cryptographically random data (Go `crypto/rand`, 64 hex chars on the wire), scoped to a single connection and discarded after use.
- Replay attack window is bounded by the ±60 second `created_at` tolerance on kind:22242 (`CREATED_AT_TOLERANCE_SECONDS`).
- Redis membership cache is advisory; a cache miss always falls through to the authoritative PHP API. Approvals are cached for 10 min, rejections for 30 s. Revocations propagate via Redis pub/sub (`essayist_member_revoked`) and forcibly close any matching authenticated connections.
- `MAX_CONNECTIONS_PER_IP` and `MAX_PREAUTH_FRAME_BYTES` bound the resources a single client can consume before completing AUTH.

---

## Extraction as a Generic Membership Wrapper

The gateway is designed to be repositionable as a standalone open-source tool. To reuse it for a different relay:

1. Replace the `MembershipChecker` implementation with your own (the interface is a single method).
2. Set `UPSTREAM_RELAY_URL` and `RELAY_PUBLIC_URL` to point at the target relay.
3. Point `POLICY_URL_TEMPLATE` at a different HTTP membership backend (or swap in a non-HTTP `MembershipChecker`).
4. The NIP-42 verification and proxy layers require no changes.

All Essayist-specific knobs are now config — there is no hardcoded URL shape. Everything else is relay-agnostic.

---

## Open Items

- [x] Remove `ports: 7779:7779` from `strfry-essayist` in production compose
- [x] PHP-side Redis pre-warming on role grant/revoke (write key + `PUBLISH essayist_member_revoked`)
- [ ] Integration test: connect without AUTH → expect `CLOSED auth-required:` / `OK … false auth-required:` reject
- [ ] Integration test: AUTH with invalid sig → expect `CLOSED restricted:` + `NOTICE` + close
- [ ] Integration test: AUTH with non-member pubkey → expect `CLOSED restricted:` + `NOTICE` + close
- [ ] Integration test: AUTH with valid member → expect proxied REQ/EVENT
- [ ] Integration test: revocation via Redis pub/sub closes live authenticated connection
- [ ] `ROLE_ESSAYIST_MEMBER` expiry cron → Redis invalidation (cron command)

