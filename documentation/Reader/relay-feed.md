# Relay Feed

**Route:** `/relay-feed`  
**Controller:** `App\Controller\Reader\RelayFeedController`

## Overview

Relay feeds let visitors browse the live kind:30023 article stream from a curated set of Nostr relays — without going through the application's ingestion, QA, or content-parsing pipelines. Articles are displayed as lightweight raw cards (title, summary, cover image). Full parsing is deferred until the user actually clicks to read.

### Available relays

To avoid over-extending server resources, relay feed subscriptions are restricted to the project relay and the configured content relays (`relay_registry.content_relays` in `services.yaml`). The index page presents these as a dropdown — arbitrary relay URLs are not accepted. Any URL that is not on the allowlist is rejected server-side with a validation error.

## Architecture

### Flow

```
Browser                      Symfony Worker                Redis / Mercure
──────                       ──────────────                ───────────────
GET /relay-feed              -                             -
  └─ renders form
POST /relay-feed
  └─ makeKey(url)            -                             setex relay_feed:url:{key}
  └─ markActive(key)         -                             setex relay_feed:active:{key}  TTL 10 min
  └─ dispatch StartRelayFeedMessage → async_low_priority
  └─ redirect to /relay-feed/{key}

[async_low_priority worker]
                             isActive? → YES
                             connect to wss://relay_url
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
  new card → prepend to list (max 100 visible)

POST /relay-feed/{key}/keepalive   (every 5 min from Stimulus)
  └─ markActive(key)         -                             refresh TTL
```

### Components

| Component | File | Responsibility |
|-----------|------|----------------|
| `RelayFeedBufferService` | `src/Service/Nostr/RelayFeedBufferService.php` | Redis buffer: URL store, buffer list, seen set, active flag |
| `StartRelayFeedMessage` | `src/Message/StartRelayFeedMessage.php` | Messenger message carrying relay URL + key |
| `StartRelayFeedHandler` | `src/MessageHandler/StartRelayFeedHandler.php` | WebSocket subscription loop, Mercure publisher, self-re-dispatch |
| `RelayFeedController` | `src/Controller/Reader/RelayFeedController.php` | HTTP routes: index form, start, show, keepalive |
| Twig templates | `templates/relay_feed/` | UI: form, feed page, article card partial |
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

The key is `substr(sha1(normalizedUrl), 0, 16)` — a deterministic 16-char hex string used in route parameters, Mercure topics, and all Redis keys.

### Mercure topic

`/relay-feed/{key}` — **public** (not private), so no subscriber JWT is required. The Mercure hub is configured with `anonymous`, meaning unauthenticated `EventSource` connections can receive events on public topics.

### Handler window

The handler runs for ~4.5 minutes per dispatch. Before exiting it checks the `relay_feed:active:{key}` flag:
- **Set** → re-dispatch `StartRelayFeedMessage` to keep the subscription alive.
- **Expired** (no viewers for >10 min) → exit without re-dispatching.

The transport is `async_low_priority` with `redeliver_timeout: 600`, giving the 4.5-min window comfortable margin.

### Card rendering

Both the server-side Twig partial (`templates/relay_feed/_card.html.twig`) and the Stimulus controller's `_prependCard()` method produce identical `.card` markup — the same structure used by the `Molecules:Card` component elsewhere in the app. This means relay feed cards inherit all existing `.card`, `.card-header`, `.card-body`, `.card-footer`, and `.article-list` styles with no extra CSS. `relay-feed.css` contains only the page scaffold (header, live indicator, relay selector form).

### Raw card data

Cards contain only metadata extracted from event tags — **no content parsing, no database writes, no QA**:

```json
{
  "id":         "hex event id",
  "pubkey":     "hex pubkey",
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

When a visitor clicks **Read**, the link resolves to `/article/{naddr}`. The existing `ArticleController` handles the "article not found → async fetch" flow: it dispatches `FetchEventFromRelaysMessage`, which goes through the full ingestion pipeline (signature verify, QA, HTML parsing, database persistence).

## Configuration

No additional environment variables are required. The feature uses the existing:
- `MERCURE_URL` / `MERCURE_JWT_SECRET` for hub publishing
- `MESSENGER_TRANSPORT_DSN` (Redis Streams) for async dispatch

The available relay list is driven by `relay_registry.project_relays` and `relay_registry.content_relays` in `config/services.yaml`. To add or remove a relay from the dropdown, edit those lists.

The `StartRelayFeedMessage` is routed to `async_low_priority` in `config/packages/messenger.yaml`.







