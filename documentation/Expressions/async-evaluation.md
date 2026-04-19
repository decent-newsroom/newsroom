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

- Topic pattern: `/expression-eval/{cacheKey}`
- Payload on success: `{"status": "ready", "count": 42}`
- Payload on error: `{"status": "error", "error": "..."}`

## Timeout Behavior

The Stimulus controller has a 60-second timeout. If no Mercure message arrives, it reloads the page. If the worker has finished by then, the cache will be warm and results render. If not, the loading page shows again (the worker is still processing).

