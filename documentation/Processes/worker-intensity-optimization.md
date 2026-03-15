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

Unchanged: `cache_latest_articles` (*/15), `cache-latest-highlights` (*/30), `media-discovery` (6h), `unfold:cache:warm` (*/30).

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

## Impact Summary

| Metric | Before | After |
|--------|--------|-------|
| Profile refresh: users loaded per cycle | ALL | Only stale (>2h) |
| Messenger consumers | 1 (both queues) | 2 (separated) |
| Login relay sync frequency | Every login | Max once per 30 min |
| Event sync scope | All-time (limit 500) | Last 24 hours |
| Cron invocations per hour | ~26 | ~16 |
| Total PHP processes in worker | 6 | 7 (extra messenger) |

