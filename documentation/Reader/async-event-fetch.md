# Async Event Fetching

## Problem

The `/e/naddr1…`, `/e/nevent1…`, `/e/note1…`, and `/article/naddr1…` routes used to
fetch events **synchronously** from Nostr relays when the event was not found in
the local database. Each relay round-trip can take several seconds, and with
only 4 FrankenPHP workers, slow relays would block the entire application,
resulting in `Maximum execution time of 15 seconds exceeded` errors and 504
gateway timeouts.

## Solution

Relay fetching uses a **two-phase strategy**: synchronous hint relay lookup
first, then async broader search as fallback.

### Phase 1 — Synchronous fast-path lookup

The controller first checks the local database, then performs a short
**synchronous** relay lookup so explicit event searches can render immediately
when relays respond quickly. `nevent` lookups merge relay hints with cached/DB
author NIP-65 relays. `naddr` lookups use relay hints plus cached/DB author
relays without performing blocking NIP-65 network discovery in the HTTP request.
If found, the event is persisted (both `Event` and `Article` entities for kind
30023/30024 when possible) and the page renders immediately with no loading
screen. Projection failures render the raw relay event instead of falling
through as not-found.

For **article naddr** lookups specifically, EventController checks the `Article`
table first as a fast path. If the article already exists, it redirects to the
article page immediately without touching relays. The `/article/naddr1…` route
simply redirects to `/e/naddr1…` — all naddr resolution is handled by
`EventController`.

### Phase 2 — Async broader search (fallback)

If no relay hints exist, or the hint relays didn't have the event:

1. A `FetchEventFromRelaysMessage` is dispatched to the `async` Messenger
   transport.
2. The controller immediately renders a **loading placeholder** page
   (`event/loading.html.twig`) with a spinner.
3. The browser subscribes to a **Mercure SSE topic** for the fetch result.
4. A background worker picks up the message, expands the relay list with the
   author's NIP-65 relays when an author pubkey is known (including `naddr`
   lookups), queries relays with worker-only longer timeouts, persists the event
   via `GenericEventProjector`, and publishes the result to Mercure. For article
   events (kind 30023/30024), `ArticleEventProjector` is also called to create
   the `Article` entity required by article routes.
5. The browser receives the Mercure update and **reloads the page** (via
   Turbo or full reload). Since the event is now in the database, the page
   renders normally.
6. If the worker reports "not found" or nothing arrives within 30 seconds,
   the UI reloads once (to catch events persisted after a Mercure race
   condition). If the loading page is served again, the UI switches to a
   **"Not found on relays"** state with a **Retry** button. The reload
   tracking uses `sessionStorage` to survive page navigation.
7. If relays return the event but persistence/projection fails, the worker
   publishes `status=error` so the UI shows a fetch-failed state instead of a
   misleading not-found message.

## Components

| Component | File |
|-----------|------|
| Message DTO | `src/Message/FetchEventFromRelaysMessage.php` |
| Async handler | `src/MessageHandler/FetchEventFromRelaysHandler.php` |
| Event controller | `src/Controller/EventController.php` (handles all naddr/nevent/note) |
| Article controller | `src/Controller/Reader/ArticleController.php` (`/article/naddr1…` redirects to `/e/…`) |
| Loading template | `templates/event/loading.html.twig` |
| Stimulus controller | `assets/controllers/content/event_fetch_controller.js` |
| Messenger routing | `config/packages/messenger.yaml` (`async` transport) |

## Mercure Topic

The topic pattern is `/event-fetch/{lookupKey}` where `lookupKey` is:

- `naddr:{kind}:{pubkey}:{identifier}` for parameterized replaceable events
- `nevent:{eventId}` for events with relay hints
- `note:{eventId}` for plain notes

`EventLookupKey` is the PHP single source of truth for `nevent`/`naddr` lookup
key strings and the `/event-fetch/` topic prefix used by the controller and
worker.

## Timeout Behaviour

The Stimulus controller subscribes to a Mercure SSE topic. If no result arrives
within 30 seconds, it sets a `sessionStorage` flag and reloads the page. If the
server still serves the loading template after this reload (meaning the event
was not found), the flag is detected on connect and the "Not found" state is
shown immediately with a Retry button. This prevents the infinite reload loop
that occurred previously when Mercure updates were missed.

After ~6 seconds of waiting, a "still searching" notice is shown to keep the
user informed.

## Production Mercure Configuration

For Mercure to work in production, the following environment variables must be
set in `.env.prod.local`:

| Variable | Example | Description |
|----------|---------|-------------|
| `MERCURE_URL` | `http://php/.well-known/mercure` | Internal hub URL (container-to-container) |
| `MERCURE_PUBLIC_URL` | `https://your-domain.com/.well-known/mercure` | Public URL sent to browsers for SSE |
| `MERCURE_JWT_SECRET` | `(openssl rand -hex 32)` | JWT secret shared between publisher and hub |

The `MERCURE_PUBLIC_URL` must match the domain users access. Without it, the
`<meta name="mercure-hub">` tag in `base.html.twig` outputs the wrong URL and
browsers fail to connect.

If running behind a reverse proxy (nginx, Cloudflare, etc.), ensure:
- **SSE buffering is disabled**: `proxy_buffering off;` (nginx) or
  `X-Accel-Buffering: no` header.
- **CORS origins** are configured in the Caddyfile Mercure block (defaults to
  `*` via `MERCURE_CORS_ORIGINS`).

## Event Types Covered

- **naddr** (NIP-33 parameterized replaceable events) — articles, curation sets, etc.
- **nevent** (NIP-01 events with relay hints)
- **note** (plain event IDs)
- **nprofile** — still a synchronous redirect (no relay fetch needed)
