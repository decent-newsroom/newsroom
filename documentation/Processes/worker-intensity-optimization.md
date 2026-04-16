# Worker Intensity Optimization

## Problem

The consolidated worker (`app:run-workers`) was doing excessive background processing:
- Profile refresh loaded **all** users every cycle regardless of staleness
- A single Messenger consumer handled both priority queues, letting slow profile/sync jobs block article and comment processing
- Every login triggered a full relay sync (15 event kinds × 500 limit × N relays) with no throttle
- Cron jobs ran more frequently than the data volume warranted

## Changes

### 1. Stale-Only Profile Refresh

**Files:** `ProfileRefreshWorkerCommand.php`, `User.php`, `UserEntityRepository.php`, `BatchUpdateProfileProjectionHandler.php`

The profile refresh worker now queries `findStaleProfiles()` instead of `findAll()`. Only users whose `lastMetadataRefresh` is older than 2 hours (or NULL) are processed. After each batch is handled, `lastMetadataRefresh` is stamped on the user entity.

This means:
- First cycle after deploy: all users are processed (all NULL)
- Subsequent cycles: only users not refreshed in 2+ hours
- Active users get refreshed via login flow anyway

### 2. Split Messenger Consumers

**File:** `RunWorkersCommand.php`

The single `messenger:consume async async_low_priority` is now two separate processes:
- **`messenger` (high-priority):** `async` transport — articles, comments, media, magazines (256 MB limit)
- **`messenger-low` (low-priority):** `async_low_priority` transport — profiles, relay lists, gateway warming, event sync (128 MB limit)

This prevents heavy relay sync jobs (which can take 30+ seconds each) from starving time-sensitive article ingestion.

### 3. Login Sync Throttling

**File:** `UserMetadataSyncListener.php`

The `UpdateRelayListMessage` dispatch (which cascades to `GatewayWarmConnectionsMessage` + `SyncUserEventsMessage`) is now throttled. If the user's `lastMetadataRefresh` is less than 30 minutes old, the dispatch is skipped entirely.

Additionally, `lastLoginAt` is tracked on the user entity for future use (e.g., excluding dormant users from profile refresh).

### 4. Scoped Event Sync

**File:** `UpdateRelayListHandler.php`

`SyncUserEventsMessage` now carries a `since` parameter set to 24 hours ago. Previously it fetched all-time events (up to 500 per relay per kind). Most older events are already in the database from previous syncs or hydration.

### 5. Reduced Cron Frequencies

**File:** `docker/cron/crontab`

| Job | Before | After | Reason |
|-----|--------|-------|--------|
| `articles:post-process` | */5 min | */10 min | QA+index pipeline rarely has >5 min of work |
| `app:fetch-highlights` | */15 min | */30 min | Makes network calls to external relays |
| `app:project-magazines` | */10 min | */30 min | Magazine events (kind 30040) are infrequent |

Unchanged: `cache-latest-articles` (*/15), `cache-latest-highlights` (*/30), `media-discovery` (6h), `unfold:cache:warm` (*/30).

### 6. Profile Refresh Interval

**File:** `compose.yaml`

| Setting | Before | After |
|---------|--------|-------|
| `--profile-interval` | 6000s (100 min) | 14400s (4 hours) |
| `--profile-batch-size` | 100 | 50 |

Combined with staleness checks, this reduces the frequency of full user table scans.

## Database Migration

`Version20260315120000` adds two nullable columns to `app_user`:
- `last_metadata_refresh` (TIMESTAMP) — when profile metadata was last refreshed
- `last_login_at` (TIMESTAMP) — when the user last logged in

Run: `docker compose exec php bin/console doctrine:migrations:migrate`

## FrankenPHP CPU Optimizations

Separate from the worker container, the **php (FrankenPHP)** container can also consume excessive CPU. These changes target the web-serving process:

### 1. JIT Mode: tracing → function-level (1255)

**File:** `frankenphp/conf.d/20-app.prod.ini`

FrankenPHP's worker mode keeps PHP processes alive between requests. With `opcache.jit = tracing`, the JIT profiler **never stops** — it continuously monitors hot paths and recompiles code, consuming 10-30% CPU even at idle. Function-level JIT (`1255`) compiles whole functions once and is idle between requests.

Buffer reduced from 128M to 64M since function-level JIT needs less memory.

### 2. Worker Thread Cap: unlimited → 4

**File:** `compose.prod.yaml`

Without an explicit count, `worker ./public/index.php` defaults to **2× CPU cores** (e.g., 16 threads on an 8-core machine). Each thread holds its own PHP worker process in memory. On shared/VPS hosting, this over-provisions and causes CPU contention.

Changed to `worker ./public/index.php 4`. Adjust based on actual load — 2 threads per available CPU core is a good starting point.

### 3. Compression: zstd+br+gzip → gzip only

**File:** `frankenphp/Caddyfile`

Caddy was running three compression algorithms (`encode zstd br gzip`). Brotli (br) is **5-10× more CPU-intensive** than gzip at comparable compression levels. Zstd is lighter but still adds overhead. Since most production deployments sit behind a reverse proxy (nginx, Cloudflare, etc.) that handles compression, running it in Caddy is double work.

Changed to `encode gzip`. If behind a proxy, compression can be disabled entirely with `encode` removed.

### 4. Static Asset Cache Headers

**File:** `frankenphp/Caddyfile`

AssetMapper generates fingerprinted filenames (e.g., `app-abc123.js`), so assets can be cached forever. Added `Cache-Control: public, max-age=31536000, immutable` for `/assets/*` and `/bundles/*`. This prevents:
- Caddy re-reading files from disk on every request
- Re-compressing the same bytes repeatedly
- Browsers re-requesting unchanged files

### 5. Mercure Bolt Tuning

**File:** `frankenphp/Caddyfile`

Added `cleanup_frequency 5m` (was continuous) to reduce Bolt DB disk I/O churn from the embedded Mercure hub. Added `heartbeat_interval 15s` to keep SSE connections alive through Docker networking and proxies. Removed `write_timeout 10s` / `dispatch_timeout 5s` — these caused premature subscriber disconnection and, combined with gzip buffering of SSE responses, produced a visible reconnect cycle every 3–4 seconds in browsers. The `encode gzip` directive now excludes `/.well-known/mercure*` paths so SSE streams are never buffered.

### 6. Memory Limit: 512M → 256M

**File:** `frankenphp/conf.d/20-app.prod.ini`

In worker mode, PHP processes are long-lived (no cold starts). 512M per worker at 4 workers = 2 GB reserved. 256M is sufficient for this application's request patterns and reduces GC pressure.

## Impact Summary

| Metric | Before | After |
|--------|--------|-------|
| Profile refresh: users loaded per cycle | ALL | Only stale (>2h) |
| Messenger consumers | 1 (both queues) | 2 (separated) |
| Login relay sync frequency | Every login | Max once per 30 min |
| Event sync scope | All-time (limit 500) | Last 24 hours |
| Cron invocations per hour | ~26 | ~16 |
| Total PHP processes in worker | 6 | 7 (extra messenger) |
| FrankenPHP worker threads | 2× CPU cores | 4 (capped) |
| JIT mode | tracing (continuous profiling) | function (compile-once) |
| Compression algorithms | 3 (zstd+br+gzip) | 1 (gzip) |
| Static asset re-compression | Every request | Cached immutably |
| PHP memory per worker | 512M | 256M |

