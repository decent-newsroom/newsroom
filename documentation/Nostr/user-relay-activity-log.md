# User-Visible Relay Activity Log

Logged-in users have a per-pubkey activity log that records every NIP-42 AUTH outcome and every publish result the relay gateway performs on their behalf. It surfaces under **Settings → Relays → Recent relay activity** and is the user-facing counterpart to the admin `RelayHealthStore` dashboard.

## Why

Two questions came up repeatedly:

1. *On article publish, does a user see a fresh AUTH challenge from auth-protected relays in their list, or is the AUTH session persisted?*
2. *Can a user see their own relay connection logs anywhere?*

The activity log answers both:

- The presence of a recent `AUTH (browser) — OK` row tells the user a challenge happened and was signed; the absence of a row for a publish that succeeded tells them the existing authed socket was reused (no new challenge).
- A `Publish — Failed` row with an error detail tells them which relay rejected the event and why, without needing operator help.

## Storage

Redis Stream `relay_user_activity:{pubkeyHex}`, capped at 200 entries via `XADD MAXLEN ~ 200`, key TTL 7 days. Wholly Redis — no DB schema change.

| Field | Type | Notes |
|---|---|---|
| `type` | `auth` \| `publish` | |
| `relay` | string | Relay URL (public form) |
| `status` | `ok` \| `pending` \| `failed` | |
| `method` | `nip07` \| `nip46` \| `none` | AUTH-only. `none` = relay accepted without a challenge |
| `event_id` | hex | Publish-only |
| `detail` | string | Optional human-readable error/context |

The Redis Stream's natural ID (`<ms>-<seq>`) doubles as the timestamp — no separate `ts` field needed at write time.

## Write hooks (gateway side)

Wired into `App\Command\RelayGatewayCommand` at four points; only fires when `$conn->pubkey` is non-empty (i.e. user-keyed connections, not anonymous shared sockets):

1. **NIP-46 server-side AUTH success** — after `Nip46AuthSigner::signAuthEvent()` returns and the AUTH frame is sent to the relay → `auth / nip46 / ok`.
2. **NIP-46 send failure** — `auth / nip46 / failed` with the exception message in `detail`. Also fires when `signAuthEvent` returns null (signer unreachable) so the user sees that the bunker fallback to Mercure was triggered.
3. **NIP-07 Mercure roundtrip start** — when the challenge is published to `/relay-auth/{pubkey}` → `auth / nip07 / pending`. Becomes `ok` once `checkPendingAuths` reads the signed event back from Redis, or `failed` if the auth-timeout elapses with no signed event.
4. **Publish OK** — every relay-side `["OK", id, accepted, message]` arriving on a user-keyed connection → `publish / status=ok|failed` with the event id and the relay's reason in `detail`.

System-traffic publishes (relay gateway warming, hydration workers using shared connections) don't touch the user activity log; their pubkey is null on the connection.

## Read

`RelayUserActivityStore::getRecent(string $pubkeyHex, int $limit = 50)` reads via `XREVRANGE` so the rendered table is newest-first by construction. Hard-capped at 200 to avoid surprises with a large `limit` argument.

## UI

Rendered server-side in `templates/settings/tabs/_relays.html.twig` as a static `<table>` (no JS needed for the initial display). Rows are colour-coded by status (`ok` = success, `pending` = warning, `failed` = error). Translation keys live under `settings.relays.activity.*` in `translations/messages.en.yaml`; other locales fall through to English consistent with the rest of the Relays tab.

## Connection idle TTL — how it ties into AUTH visibility

Per the gateway's `--user-idle-timeout` (default **7200 s = 2 h**), an authenticated user-keyed WebSocket to a given relay is reused across publishes until 2 h of inactivity elapses. Within that window:

- No new AUTH log entry per publish.
- The publish flows directly through the existing socket; the user sees only `Publish — OK` (or `Failed`).

Once the socket idles out (or the relay drops it, or the gateway restarts), the next operation reopens the socket. The relay re-issues a `AUTH` challenge, and one of the following appears in the log:

- `AUTH (remote signer) — OK` (NIP-46, silent for the user — server-signed via the encrypted 8 h Redis session).
- `AUTH (browser) — Pending` then `OK` (NIP-07 — the browser signs via Mercure roundtrip; most extensions auto-sign `kind:22242`).
- `AUTH (browser) — Failed` if the browser tab is closed or the extension rejects, with `detail = "browser did not sign within timeout"`.

## Lifecycle

The activity store is **not** cleared on logout today — entries simply roll off via the 7-day key TTL. If we add a privacy switch later, `RelayUserActivityStore::clear($pubkey)` exists for that purpose; a logout listener can wire it.

## Non-goals

- This is not a transport-level packet log. Individual REQ/EOSE/EVENT frames are not recorded — they would dominate the stream and obscure the AUTH/publish signal that's actually useful.
- This is not an admin tool. The admin counterpart for cross-user / per-relay diagnostics is `RelayHealthStore` and the existing `/admin/relay/...` dashboards.

