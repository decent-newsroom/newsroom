# Async Event Fetching

## Problem

The `/e/naddr1…`, `/e/nevent1…`, `/e/note1…`, and `/article/naddr1…` routes used to
fetch events **synchronously** from Nostr relays when the event was not found in
the local database. Each relay round-trip can take several seconds, and with
only 4 FrankenPHP workers, slow relays would block the entire application,
resulting in `Maximum execution time of 15 seconds exceeded` errors and 504
gateway timeouts.

## Solution

Relay fetching is now **asynchronous**. When an event is not found locally:

1. A `FetchEventFromRelaysMessage` is dispatched to the `async` Messenger
   transport.
2. The controller immediately renders a **loading placeholder** page
   (`event/loading.html.twig`) with a spinner.
3. The browser subscribes to a **Mercure SSE topic** for the fetch result.
4. A background worker picks up the message, queries relays, persists the
   event via `GenericEventProjector`, and publishes the result to Mercure.
5. The browser receives the Mercure update and **reloads the page** (via
   Turbo or full reload). Since the event is now in the database, the page
   renders normally.
6. If the worker reports "not found" or nothing arrives within 30 seconds,
   the UI switches to a **"Not found on relays"** state with a **Retry**
   button.

## Components

| Component | File |
|-----------|------|
| Message DTO | `src/Message/FetchEventFromRelaysMessage.php` |
| Async handler | `src/MessageHandler/FetchEventFromRelaysHandler.php` |
| Event controller | `src/Controller/EventController.php` |
| Article controller | `src/Controller/Reader/ArticleController.php` |
| Status API endpoint | `src/Controller/Api/EventFetchStatusController.php` |
| Loading template | `templates/event/loading.html.twig` |
| Stimulus controller | `assets/controllers/content/event_fetch_controller.js` |
| Messenger routing | `config/packages/messenger.yaml` (`async` transport) |

## Mercure Topic

The topic pattern is `/event-fetch/{lookupKey}` where `lookupKey` is:

- `naddr:{kind}:{pubkey}:{identifier}` for parameterized replaceable events
- `nevent:{eventId}` for events with relay hints
- `note:{eventId}` for plain notes

## Redis Status Key

The handler stores the fetch result at `event_fetch:{lookupKey}` (5-minute TTL)
so that subsequent page loads can check the status without re-dispatching.

## Polling Status Endpoint

`GET /api/event-fetch-status/{lookupKey}` reads the Redis status key and returns
JSON: `{ "status": "pending" | "found" | "not_found" | "error" }`.

The Stimulus controller polls this endpoint every 3 seconds alongside Mercure
SSE, providing a reliable fallback when SSE is misconfigured or unavailable.

## Fallback Behaviour

The Stimulus controller uses a two-tier fallback strategy:

1. **Mercure SSE + polling** (default): subscribes to the Mercure topic AND polls
   the status API endpoint every 3 seconds. Whichever responds first wins.
2. **Polling only**: if the Mercure hub URL is missing, only the status API
   endpoint is polled.
3. **Timed reload**: if neither Mercure nor the status URL are available, falls
   back to a simple timed reload after 8 seconds.

After ~30 seconds with no result, the UI switches to a "Not found on relays"
state with a Retry button.

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

