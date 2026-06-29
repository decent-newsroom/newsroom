# Relay Feed

**Route:** `/relay-feed`  
**Controller:** `App\Controller\Reader\RelayFeedController`

## Overview

Relay feeds let visitors browse the live kind:30023 article stream from a curated set of Nostr relays — without going through the application's ingestion, QA, or content-parsing pipelines. Articles are displayed as lightweight raw cards (title, summary, cover image) using the shared `.card` component. Full parsing is deferred until the user actually clicks to read.

### Available relays

Feed subscriptions are restricted to the configured project relay and content relays (`relay_registry.project_relays` + `relay_registry.content_relays` in `services.yaml`). The index page presents these as a `<select>` dropdown — arbitrary relay URLs are not accepted. Any URL not on the allowlist is rejected server-side with a validation error.

To add or remove a relay from the dropdown, edit the lists in `config/services.yaml`.

## Architecture

### Flow

```
Browser                      Symfony Worker                Redis / Mercure
──────                       ──────────────                ───────────────
GET /relay-feed
  └─ renders dropdown (allowed_relays from RelayRegistry)
POST /relay-feed
  └─ allowlist check (RelayUrlNormalizer)
  └─ makeKey(normalizedUrl)  -                             setex relay_feed:url:{key}
  └─ markActive(key)         -                             setex relay_feed:active:{key}  TTL 10 min
  └─ dispatch StartRelayFeedMessage → async_relay_feeds
  └─ redirect to /relay-feed/{key}

[async_relay_feeds worker  (one of three parallel consumers)]
                             isActive? → YES
                             resolveToLocalUrl(relayUrl)   (project URL → internal Docker URL)
                             connect via WebSocket
                             subscribe kind:30023 (last 24h)
                             ─── per event ────────────────
                             alreadySeen? → skip
                             extractCard(event)
                             markSeen + pushToBuffer       relay_feed:buffer:{key}  ← LPUSH+LTRIM 100
                             hub->publish(Update)          /relay-feed/{key}
                             ─── after 4.5 min ────────────
                             close WebSocket
                             isActive? → re-dispatch or stop

GET /relay-feed/{key}
  └─ getBuffer(key)          -                             lRange relay_feed:buffer:{key}
  └─ markActive(key)         -                             refresh TTL
  └─ renders feed.html.twig (buffered cards + Mercure subscription)

[EventSource → /.well-known/mercure?topic=/relay-feed/{key}]
  ← Mercure SSE ─────────────────────────────────────────────────
  new card → prepend to .article-list (max 100 visible)

POST /relay-feed/{key}/keepalive   (every 5 min from Stimulus)
  └─ markActive(key)         -                             refresh TTL
```

### Components

| Component | File | Responsibility |
|-----------|------|----------------|
| `RelayFeedBufferService` | `src/Service/Nostr/RelayFeedBufferService.php` | Redis buffer: URL store, buffer list, seen set, active flag |
| `StartRelayFeedMessage` | `src/Message/StartRelayFeedMessage.php` | Messenger message carrying relay URL + key |
| `StartRelayFeedHandler` | `src/MessageHandler/StartRelayFeedHandler.php` | WebSocket subscription loop, Mercure publisher, self-re-dispatch |
| `RelayFeedController` | `src/Controller/Reader/RelayFeedController.php` | HTTP routes: index (dropdown), start (POST + allowlist check), show, keepalive |
| Twig templates | `templates/relay_feed/` | `index.html.twig` (form), `feed.html.twig` (live feed), `_card.html.twig` (adapter to `Molecules:Card`) |
| Stimulus controller | `assets/controllers/content/relay_feed_controller.js` | EventSource subscription, card rendering, keepalive ping |
| CSS | `assets/styles/04-pages/relay-feed.css` | Page scaffold only (header, indicator, form). Cards use the shared `.card` system. |

### Redis Keys

| Key | Type | TTL | Purpose |
|-----|------|-----|---------|
| `relay_feed:url:{key}` | String | 1 h | Canonical relay URL for the key |
| `relay_feed:buffer:{key}` | List | 1 h | Rolling buffer of up to 100 JSON cards (newest at index 0) |
| `relay_feed:seen:{key}` | Set | 1 h | Deduplication set of seen event IDs |
| `relay_feed:active:{key}` | String | 10 min | Presence flag; renewed by page loads and keepalive pings |

### Relay key

`substr(sha1(normalizedUrl), 0, 16)` — a deterministic 16-char hex string used in route parameters, Mercure topics, and all Redis keys.

### Mercure topic

`/relay-feed/{key}` — **public** (not private), so no subscriber JWT is required. The Mercure hub has `anonymous` enabled, meaning unauthenticated `EventSource` connections receive events on public topics without a cookie or authorization header.

### Handler window

The handler runs for ~4.5 minutes per dispatch. Before exiting it checks the `relay_feed:active:{key}` flag:
- **Set** → re-dispatch `StartRelayFeedMessage` to keep the subscription alive.
- **Expired** (no viewers for >10 min) → exit without re-dispatching.

The transport is `async_relay_feeds` (`redeliver_timeout: 600`), giving the 4.5-min window comfortable margin. Three parallel consumers run on this transport (spawned by `app:run-workers` as `relay-feed-1/2/3`), so up to three feeds can run concurrently without queuing behind each other or blocking the `async_low_priority` queue.

### Project relay resolution

When the project relay's public hostname (e.g. `wss://relay.decentnewsroom.com`) is selected, `StartRelayFeedHandler` calls `RelayRegistry::resolveToLocalUrl()` before opening the WebSocket. This converts the public URL to the internal Docker hostname (`strfry`), avoiding an unnecessary external round-trip.

### Card rendering

Buffered articles are normalized by `templates/relay_feed/_card.html.twig` and
rendered through the shared `Molecules:Card` component used by Discover's
Recent and Featured Writers tabs. Live Mercure arrivals cannot invoke Twig in
the browser, so `relay_feed_controller.js::_prependCard()` emits the same
`.article-card` structure, metadata, responsive media classes, stretched title
link, and bookmark footer. The list container uses `.article-list`; no
card-specific CSS exists in `relay-feed.css`.

Author bylines are shown on both buffered and live cards:
- **Buffered/server-rendered cards** use `<twig:Molecules:UserFromNpub>` with `article.pubkey`, which resolves profile metadata and links to the author page.
- **Live Mercure cards** use `npub` from the payload for a direct `/p/{npub}` author link, with a short hex pubkey fallback if `npub` is unavailable.

### Raw card data

Cards contain only metadata extracted from event tags — **no content parsing, no database writes, no QA**:

```json
{
  "id":         "hex event id",
  "pubkey":     "hex pubkey",
  "npub":       "npub1...",
  "created_at": 1234567890,
  "title":      "Article Title",
  "summary":    "Short description",
  "image":      "https://example.com/cover.jpg",
  "d_tag":      "article-slug",
  "naddr":      "naddr1...",
  "relay":      "wss://relay.example.com"
}
```

### Deferred ingestion

When a visitor clicks a card, the link resolves to `/article/{naddr}`. The existing `ArticleController` handles the "article not found → async fetch" flow: it dispatches `FetchEventFromRelaysMessage`, which goes through the full ingestion pipeline (signature verify, QA, HTML parsing, database persistence).

## Configuration

No additional environment variables are required. The feature uses the existing:
- `MERCURE_URL` / `MERCURE_JWT_SECRET` for hub publishing
- `MESSENGER_TRANSPORT_DSN` (Redis Streams) for async dispatch

The `StartRelayFeedMessage` is routed to `async_relay_feeds` in `config/packages/messenger.yaml`. Three parallel consumers for this transport are started by `app:run-workers` (keys `relay-feed-1`, `relay-feed-2`, `relay-feed-3`).

### Relay suggestion

The index page (`/relay-feed`) also embeds a public relay-suggestion form below the relay selector. It publishes a kind-1 Nostr note tagging the platform operators (same `nostr--nostr-single-sign` pattern as the feedback form, no custom controller). Recipients are resolved from npubs in `RelayFeedController::recipients()`. The suggestion carries a `["t", "nostr-relay"]` tag.
