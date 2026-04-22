# Async Expression Evaluation

## Overview

Expression evaluation (kind:30880 events) can be slow because it involves fetching events from multiple Nostr relays and running a multi-stage pipeline. To keep the UI responsive, evaluation is performed asynchronously when the cache is cold.

## How It Works

### Flow

1. **User visits** `/expression/{npub}/{dtag}`
2. **Controller checks Redis cache** via `ExpressionService::getCachedResults()`
3. **Cache hit** → render results immediately (instant page load)
4. **Cache miss** → dispatch `EvaluateExpressionMessage` to Messenger async transport, render loading template with Mercure SSE subscription
5. **Worker evaluates** the expression, writes results to Redis cache, publishes Mercure update to `/expression-eval/{cacheKey}`
6. **Browser receives** SSE message, reloads page via Turbo → cache is now warm → instant render

### Cache TTL

Results are cached for 300 seconds (5 minutes) by default, configurable via `expressionCacheTtl` on `FeedCacheService`. The cache key is per-user (incorporates contacts + interests context).

## Components

| Component | File | Role |
|-----------|------|------|
| Message | `src/Message/EvaluateExpressionMessage.php` | DTO for async dispatch |
| Handler | `src/MessageHandler/EvaluateExpressionHandler.php` | Evaluates, caches, pushes Mercure |
| Controller | `src/Controller/Newsroom/ExpressionController.php` | Cache check → async dispatch or render |
| Loading template | `templates/expressions/view_loading.html.twig` | Spinner + Mercure subscription |
| Stimulus controller | `assets/controllers/content/expression_feed_controller.js` | SSE listener, auto-reload |
| Cache service | `src/ExpressionBundle/Service/FeedCacheService.php` | Redis read/write with TTL |

## Messenger Routing

`EvaluateExpressionMessage` is routed to the dedicated `async_expressions` transport, processed by an isolated `messenger-expressions` consumer subprocess in the `worker` service (`app:run-workers`). It is intentionally kept off the shared `async` queue so that a backlog of bulk content fetches (articles, comments, media, magazines) can never delay a user-initiated expression evaluation — the loading page is blocking on Mercure for this result.

## Mercure Topics

- Topic pattern: `/expression-eval/{cacheKey}` (and `/spell-eval/{cacheKey}` for spells)
- Payload on progress: `{"status": "log", "level": "info|warning|error|...", "message": "...", "context": {...}, "ts": 1713782400000}`
- Payload on success: `{"status": "ready", "count": 42}`
- Payload on error: `{"status": "error", "error": "..."}`

### Progress log streaming

While the worker is evaluating, every PSR-3 record emitted by the bundle
(`ExpressionService`, `ExpressionRunner`, the `SourceResolver` family,
`FeedCacheService`) is teed to the same Mercure topic as a `status: "log"`
payload. The loading template renders a live scrolling panel (see
`expression-log.css`) so the user can see which stage is running, how many
events were fetched from relays, cache hits/misses, etc. `debug`-level
records are filtered out at the source by `MercureProgressLogger` to avoid
flooding the hub during tight per-item loops.

The wiring is:

- `App\ExpressionBundle\Logging\LoggerSwitch` is bound as the PSR-3
  `LoggerInterface` for every bundle service (see
  `src/ExpressionBundle/Resources/config/services.yaml`). Its default
  delegate is the Monolog logger — sync call sites (API controllers, tests)
  behave as before.
- The async handlers (`EvaluateExpressionHandler`, `EvaluateSpellHandler`)
  construct a `TeeLogger(monolog, MercureProgressLogger(hub, topic))` and
  push it onto the switch for the duration of `__invoke`, popping in a
  `finally` block.
- Mercure's bolt transport retains published updates, so a browser that
  reconnects during evaluation gets missed entries replayed via
  `Last-Event-ID` automatically.

## Diagnostics

If no updates reach the browser:

1. Check worker logs for `Mercure publish failed for expression evaluation`
   — the handler escalates this to `error` with full exception class and
   HTTP message.
2. Verify `MERCURE_URL` and `MERCURE_JWT_SECRET` are identical between the
   `worker` and `php` services (`docker compose exec worker printenv`).
3. In the browser devtools Network tab, confirm the EventSource connection
   to `MERCURE_PUBLIC_URL` returns `200` with
   `content-type: text/event-stream`. A self-signed TLS cert on
   `https://localhost:8443` silently blocks EventSource until accepted.

## Timeout Behavior

The Stimulus controller has a 60-second timeout. If no Mercure message arrives, it reloads the page. If the worker has finished by then, the cache will be warm and results render. If not, the loading page shows again (the worker is still processing).

