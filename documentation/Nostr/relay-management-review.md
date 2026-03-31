# Relay Management — Architecture Review & Improvement Suggestions

**Date:** 2026-03-31  
**Scope:** `RelayRegistry`, `RelayHealthStore`, `UserRelayListService`, `NostrRelayPool`, `RelaySetFactory`, `RelayGatewayCommand`, `RelayGatewayClient`, `RelayAdminService`, `GatewayConnection`, Stimulus relay settings controller, admin UI

---

## Overall Assessment

The relay management subsystem is **well-architected**. The consolidation from scattered hardcoded constants into `RelayRegistry`, the purpose-based enum (`RelayPurpose`), the stale-while-revalidate resolution in `UserRelayListService`, the health-aware ranking in `NostrRelayPool`, and the optional gateway with NIP-42 AUTH roundtrip signing are all sound design decisions.

The codebase shows clear progression from early ad-hoc patterns toward a layered, single-responsibility architecture. The documentation in `documentation/Strfry/relay-pool.md` and "Lessons Learned" section demonstrates mature operational awareness.

That said, there are several areas where the implementation can be tightened, deduplicated, made more resilient, and better tested.

---

## 1. URL Normalization Is Scattered and Inconsistent

**Problem:** Relay URL normalization logic is duplicated across 6+ files with slightly different approaches:

| Location | Normalization Method |
|----------|---------------------|
| `NostrRelayPool::normalizeRelayUrl()` | `trim()` + `rtrim($url, '/')` |
| `RelayRegistry::isProjectRelay()` | `rtrim(strtolower($url), '/')` |
| `RelayRegistry::isConfiguredRelay()` | `rtrim(strtolower($url), '/')` |
| `RelayRegistry::ensureLocalRelayInList()` | `rtrim(trim($local), '/')` (no lowercase) |
| `RelayHealthStore::key()` | `rtrim(trim($relayUrl), '/')` (no lowercase) |
| `RelayHealthStore::muteRelay()` | `rtrim(trim($relayUrl), '/')` |
| `GatewayConnection::buildKey()` | `rtrim(strtolower($relayUrl), '/')` |
| `RelayAdminService::isLocalRelay()` | `rtrim(strtolower(trim($url)), '/')` |
| `UpdateRelayListHandler` | Inline `rtrim(strtolower($url), '/')` |

The `NostrRelayPool` version **doesn't lowercase**, while others do. `RelayHealthStore` **doesn't lowercase** the key, meaning `wss://Relay.Damus.io` and `wss://relay.damus.io` would produce different health keys.

**Suggestion:** Extract a single static `RelayUrlNormalizer::normalize(string $url): string` utility method (or add it to `RelayRegistry`) and use it everywhere:

```php
// src/Util/RelayUrlNormalizer.php
final class RelayUrlNormalizer
{
    public static function normalize(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }
}
```

Replace all 7+ ad-hoc normalization calls with this single source of truth.

---

## 2. `NostrRelayPool` Duplicates `RelayRegistry` Responsibilities

**Problem:** `NostrRelayPool` has methods that directly duplicate `RelayRegistry`:

- `NostrRelayPool::getLocalRelay()` — duplicates `RelayRegistry::getLocalRelay()`
- `NostrRelayPool::ensureLocalRelayInList()` — duplicates `RelayRegistry::ensureLocalRelayInList()`
- `NostrRelayPool::getRelayRegistry()` — exposes the inner registry (leaky abstraction)
- `NostrRelayPool::getHealthStore()` — exposes the inner health store

The pool still stores its own `$nostrDefaultRelay` string independently of the registry.

**Suggestion:** Remove the duplicated methods from `NostrRelayPool`, make callers use `RelayRegistry` directly. The pool should be a pure **connection manager** — it opens/closes/reuses connections and records health metrics. Relay selection/URL logic belongs in `RelayRegistry` and `RelaySetFactory`.

---

## 3. `isValidRelay()` Rejects Local Relay URLs  

**Problem:** In `UserRelayListService`:

```php
private function isValidRelay(string $url): bool
{
    return str_starts_with($url, 'wss://') && !str_contains($url, 'localhost');
}
```

This rejects `ws://` URLs entirely, which means the **local relay** (`ws://strfry:7777`) would never pass this validation. In practice this is fine because the local relay is prepended separately by `getRelaysForUser()`, but it's a hidden assumption that could break if someone restructures the relay list building.

It also rejects any user relay containing "localhost" in the path (e.g., `wss://relay.localhost.com` — unlikely but technically valid).

**Suggestion:** Make validation more intentional:
```php
private function isValidRelay(string $url): bool
{
    $normalized = RelayUrlNormalizer::normalize($url);
    return (str_starts_with($normalized, 'wss://') || str_starts_with($normalized, 'ws://'))
        && !str_contains(parse_url($normalized, PHP_URL_HOST) ?? '', 'localhost');
}
```

Or, since the local relay is handled separately and user relays must be public:
```php
private function isValidExternalRelay(string $url): bool
{
    return str_starts_with($url, 'wss://') && filter_var($url, FILTER_VALIDATE_URL) !== false;
}
```

---

## 4. Health Score Algorithm Is Too Simple

**Problem:** The `getHealthScore()` calculation:

```php
$failurePenalty = min($health['consecutive_failures'] / 10.0, 1.0);
$score = 1.0 - $failurePenalty;

if ($health['avg_latency_ms'] !== null) {
    $latencyFactor = max(0.0, 1.0 - ($health['avg_latency_ms'] / 2000.0));
    $score = ($score * 0.7) + ($latencyFactor * 0.3);
}
```

Issues:
- **No time decay**: A relay that failed 5 times yesterday but has been healthy for the last 6 hours still scores poorly. There's no recency weighting.
- **No success rate**: Only consecutive failures matter. A relay with 95% success rate but occasional bursts of 3 consecutive failures would be marked unhealthy.
- **latency_ms EMA is unbounded**: The exponential moving average can grow very large if a relay is consistently slow, but there's no cap or reset mechanism.

**Suggestion:** Add a `last_success` recency bonus:

```php
public function getHealthScore(string $relayUrl): float
{
    if ($this->isMuted($relayUrl)) {
        return 0.0;
    }
    $health = $this->getHealth($relayUrl);

    // Failure penalty
    $failurePenalty = min($health['consecutive_failures'] / 10.0, 1.0);
    $score = 1.0 - $failurePenalty;

    // Recency bonus: relays that succeeded recently get a boost
    if ($health['last_success'] !== null) {
        $hoursSinceSuccess = (time() - $health['last_success']) / 3600.0;
        $recencyFactor = max(0.0, 1.0 - ($hoursSinceSuccess / 24.0)); // decays over 24h
        $score = ($score * 0.8) + ($recencyFactor * 0.2);
    }

    // Latency factor
    if ($health['avg_latency_ms'] !== null) {
        $latencyFactor = max(0.0, 1.0 - ($health['avg_latency_ms'] / 2000.0));
        $score = ($score * 0.7) + ($latencyFactor * 0.3);
    }

    return max(0.0, min(1.0, $score));
}
```

---

## 5. `RelayHealthStore::getAllKnownRelayUrls()` Uses `KEYS *` (Performance Risk)

**Problem:**

```php
public function getAllKnownRelayUrls(): array
{
    $keys = $this->redis->keys(self::KEY_PREFIX . '*');
    // ...
}
```

`KEYS` is O(N) and blocks Redis for the entire scan. In production with thousands of user relay lists generating health keys, this can cause latency spikes.

**Suggestion:** Use `SCAN` instead:

```php
public function getAllKnownRelayUrls(): array
{
    try {
        $urls = [];
        $iterator = null;
        while ($keys = $this->redis->scan($iterator, self::KEY_PREFIX . '*', 100)) {
            foreach ($keys as $key) {
                $urls[] = str_replace(self::KEY_PREFIX, '', $key);
            }
        }
        return $urls;
    } catch (\RedisException $e) {
        $this->logger->debug('RelayHealthStore: failed to scan relay_health keys', ['error' => $e->getMessage()]);
        return [];
    }
}
```

Alternatively, maintain a Redis SET of known relay URLs alongside the hash keys, and read from that.

---

## 6. `RelayAdminService` Has Redundant Constructor Parameter

**Problem:** `RelayAdminService` takes `?string $nostrDefaultRelay = null` as a constructor argument, but this value is already available through the injected `RelayRegistry`:

```php
// Current: duplicated
$relayUrl = $this->nostrDefaultRelay ?? 'ws://strfry:7777';

// Should be:
$relayUrl = $this->relayRegistry->getLocalRelay() ?? 'ws://strfry:7777';
```

The hardcoded fallback `'ws://strfry:7777'` also appears in multiple places in this service.

**Suggestion:** Remove `$nostrDefaultRelay` from `RelayAdminService` constructor and use `$this->relayRegistry->getLocalRelay()` consistently. Extract the `'ws://strfry:7777'` default to a constant or to the registry itself.

---

## 7. `UserRelayListService::persistToDatabase()` Creates Synthetic Event IDs

**Problem:**

```php
$event->setId('relay_list_' . $hex . '_' . $newCreatedAt);
```

This creates non-standard event IDs that don't match the Nostr spec (event IDs should be SHA-256 hashes). If any code path tries to verify event signatures or validate the ID hash, these synthetic events will fail.

The `setSig('')` also means these events can't pass any verification.

**Suggestion:** Either:
1. Mark these events explicitly as synthetic (add a flag column or use a different storage mechanism), or
2. Compute the actual Nostr event ID from the content hash (even for server-generated events), or
3. At minimum, add a docblock warning that these events are server-synthesized and must never be relayed outbound.

---

## 8. No Circuit Breaker for Relay Network Fetches

**Problem:** `UserRelayListService::fromNetwork()` makes blocking network calls to profile relays. If profile relays are down or very slow, this blocks the calling request. The stale-while-revalidate pattern helps (DB and cache are checked first), but on a cold start or first-time user, the network fetch is the only path.

**Suggestion:** Add a circuit breaker pattern:
- If profile relays have been failing consistently (check `RelayHealthStore`), skip the network fetch entirely and return `null` immediately.
- Consider making the network fetch always async (dispatch `UpdateRelayListMessage`) and return DB/cache/fallback in the synchronous path.

```php
private function fromNetwork(string $hex): ?array
{
    // Circuit breaker: skip network if all profile relays are unhealthy
    $profileRelays = $this->relayRegistry->getProfileRelays();
    $healthyRelays = array_filter($profileRelays, fn($url) => $this->healthStore->isHealthy($url));
    if (empty($healthyRelays)) {
        $this->logger->info('UserRelayListService: all profile relays unhealthy, skipping network fetch');
        return null;
    }
    // ... existing fetch logic, but only query $healthyRelays
}
```

*Note:* `UserRelayListService` doesn't currently have a `$healthStore` dependency — adding one would be required.

---

## 9. No Unit Tests for Core Relay Components

**Problem:** There are zero unit tests for:
- `RelayRegistry` — purpose resolution, deduplication logic, LOCAL/PROJECT overlap, `ensureLocalRelayInList()`
- `RelayHealthStore` — scoring algorithm, muting behavior, EMA latency calculation
- `UserRelayListService` — the stale-while-revalidate logic, relay list parsing, `remapProjectRelayToLocal()`
- `RelaySetFactory` — `forAuthor()`, `forAuthorWithFallback()` fallback chains
- `GatewayConnection` — key building, normalization

These are core infrastructure components with non-trivial logic.

**Suggestion:** Add unit tests for at least:
- `RelayRegistry`: test that `getForPurposes([LOCAL, PROJECT])` deduplicates when LOCAL is set
- `RelayRegistry`: test `isProjectRelay()` with trailing slash variants
- `RelayRegistry`: test `ensureLocalRelayInList()` deduplication
- `RelayHealthStore`: test health score with various failure/latency combinations
- `UserRelayListService`: test `parseRelayListEvent()` with read/write/both markers
- `GatewayConnection::buildKey()`: test URL normalization variants

---

## 10. Gateway Command Is a 2,290-Line Monolith

**Problem:** `RelayGatewayCommand.php` is 2,290 lines — it manages connections, processes Redis streams, handles WebSocket messages, implements AUTH flows, manages pending queries/publishes, performs maintenance, and writes heartbeats. This makes it very hard to test, debug, or modify individual behaviors.

**Suggestion:** Extract into focused classes:
- `GatewayConnectionManager` — opening, closing, reconnecting, idle timeout
- `GatewayQueryProcessor` — handling incoming query requests, managing pending correlations
- `GatewayPublishProcessor` — handling publish requests, tracking per-relay results
- `GatewayAuthHandler` — AUTH challenge/response flow, Mercure integration, pending auth tracking
- `GatewayMaintenanceLoop` — heartbeat, cleanup, stream trimming

The command itself would become a thin coordinator that wires these together in the event loop.

---

## 11. Hardcoded Home Relay URL in Stimulus Controller

**Problem:** The settings relay controller has a hardcoded URL:

```javascript
addHomeRelay(event) {
    const url = 'wss://relay.decentnewsroom.com';
```

This should come from the backend (server configuration) so it stays in sync with `relay_registry.project_relays` in `services.yaml`.

**Suggestion:** Add a Stimulus value for the project relay URL:

```twig
data-nostr--nostr-settings-relays-home-relay-value="{{ project_relay_url }}"
```

And use it in the controller:
```javascript
addHomeRelay(event) {
    const url = this.homeRelayValue;
```

---

## 12. `RelayAdminService::getRecentEvents()` Bypasses the Pool

**Problem:** Several methods in `RelayAdminService` create raw `new Relay($relayUrl)` instances instead of using `NostrRelayPool`:

```php
$relay = new Relay($relayUrl);   // line 85
$relay = new Relay($relayUrl);   // line 129
```

This bypasses health tracking, connection reuse, and the gateway. While acceptable for an admin tool, it means admin queries don't benefit from the same infrastructure as production queries.

**Suggestion:** Use `$this->relayPool->sendToRelays()` for consistency. This also means admin queries would show up in health metrics, giving a more accurate picture of relay health.

---

## 13. Missing `declare(strict_types=1)` in `NostrRelayPool`

**Problem:** `NostrRelayPool.php` is missing the strict types declaration that every other file in the relay subsystem has:

```php
<?php

namespace App\Service\Nostr;  // Missing declare(strict_types=1)
```

**Suggestion:** Add `declare(strict_types=1);` for consistency and type safety.

---

## 14. Health Store TTL Could Cause Silent Data Loss

**Problem:** `RelayHealthStore` uses a 24-hour TTL on all health keys:

```php
private const TTL = 86400; // 24 hours
```

If a relay hasn't been used in 24 hours, its entire health history is silently dropped. The next time it's used, it starts from scratch with no failure history, potentially allowing a previously-muted relay to be tried again without the admin knowing the history was lost.

**Suggestion:**
- Extend TTL to 7 days for configured relays (they'll always be refreshed by workers anyway).
- Store mute state separately (already done via `relay_muted_urls` SET — good).
- Consider persisting critical health data (total failure count, first failure time) to PostgreSQL for long-term relay reputation tracking.

---

## 15. `getRelaysForUser()` Limit May Truncate User's Own Relays

**Problem:** `UserRelayListService::getRelaysForUser()` applies a hard `$limit = 5` after combining local + user + registry relays:

```php
return array_slice($relays, 0, $limit);
```

If a user has 10 relays and the local relay + 4 user relays fill the 5 slots, none of the registry defaults are included. This means content relays might be excluded from fallback queries. Conversely, if the limit is raised, more connections are opened (slower).

**Suggestion:** Use a more intelligent merging strategy:
- Always include local (1 slot)
- Take up to 3 user relays
- Fill remaining slots with registry defaults
- Or make the limit configurable per call site based on the operation (reads need more diversity than publishes)

---

## Summary Priority Matrix

| # | Issue | Severity | Effort | Impact |
|---|-------|----------|--------|--------|
| 1 | URL normalization scattered | Medium | Low | Prevents subtle bugs |
| 2 | Pool duplicates registry | Low | Medium | Cleaner architecture |
| 3 | `isValidRelay()` edge cases | Low | Low | Correctness |
| 4 | Health score too simple | Medium | Low | Better relay selection |
| 5 | `KEYS *` in Redis | High | Low | Production performance |
| 6 | Redundant constructor param | Low | Low | Code hygiene |
| 7 | Synthetic event IDs | Medium | Medium | Data integrity |
| 8 | No circuit breaker | Medium | Medium | Resilience |
| 9 | No unit tests | High | Medium | Confidence in changes |
| 10 | Gateway monolith | Medium | High | Maintainability |
| 11 | Hardcoded relay in JS | Low | Low | Configuration drift |
| 12 | Admin bypasses pool | Low | Low | Consistency |
| 13 | Missing strict_types | Low | Trivial | Consistency |
| 14 | Health TTL data loss | Medium | Low | Operational awareness |
| 15 | Relay limit truncation | Low | Low | Query completeness |

**Recommended first actions:** #5 (KEYS → SCAN), #1 (normalize utility), #9 (tests for RelayRegistry + health score), #13 (strict_types).

---

## Implementation Log

### Actions Taken (2026-03-31)

The four recommended first actions were implemented in a single pass. Reasoning for prioritization:

1. **#5 was highest severity** — `KEYS *` is a known Redis anti-pattern that blocks the entire server. This was a production risk with a trivial fix.
2. **#1 was the foundation** — before adding tests, normalizing URL comparison across the codebase ensures the tests verify correct (unified) behavior, not fragmented logic.
3. **#13 was trivial** — one line, zero risk, included while touching `NostrRelayPool.php` anyway.
4. **#9 was the safety net** — tests for the newly unified logic lock in correctness and prevent regressions.
5. **#4 was bonus** — the health score improvement was low-effort (same file as #5) and meaningfully improves relay selection for recovered relays.

#### #1 — URL Normalization Utility (Implemented ✅)

**Created:** `src/Util/RelayUrlNormalizer.php`
- Static `normalize(string $url): string` — trim + lowercase + strip trailing slash
- Static `equals(string $a, string $b): bool` — convenience comparator

**Updated consumers (7 files):**
- `RelayRegistry` — `isProjectRelay()`, `isConfiguredRelay()`, `ensureLocalRelayInList()`
- `RelayHealthStore` — `key()`, `muteRelay()`, `unmuteRelay()`, `isMuted()`
- `NostrRelayPool` — `normalizeRelayUrl()` now delegates to `RelayUrlNormalizer`
- `GatewayConnection` — `buildKey()`
- `RelayAdminService` — `isLocalRelay()`
- `UpdateRelayListHandler` — relay filtering lambda
- `RelayGatewayCommand` — `resolveRelayUrlForAuth()`

**Key change:** `RelayHealthStore::key()` previously did NOT lowercase, meaning `wss://Relay.Damus.io` and `wss://relay.damus.io` produced different Redis keys. Now both normalize to the same key.

**Risk note:** Existing health data keyed under the old (non-lowercased) format will become orphaned. This is acceptable because health data has a 24h TTL and will self-heal as new data is written under normalized keys. No migration needed.

#### #5 — KEYS → SCAN (Implemented ✅)

**Changed:** `RelayHealthStore::getAllKnownRelayUrls()`

Replaced `$this->redis->keys(self::KEY_PREFIX . '*')` with a `SCAN` loop using `$iterator` reference and batch size of 100.

Also switched from `str_replace(KEY_PREFIX, '', $key)` to `substr($key, $prefixLen)` — the original `str_replace` would incorrectly truncate if `relay_health:` appeared elsewhere in the URL string (unlikely but technically possible).

**Outcome:** Admin gateway dashboard (`/admin/relay/gateway`) no longer risks blocking Redis on instances with many tracked relay URLs.

#### #4 — Health Score Recency Bonus (Implemented ✅)

**Changed:** `RelayHealthStore::getHealthScore()`

Added a recency component (20% weight) that decays linearly over 24 hours since `last_success`. The previous algorithm only considered consecutive failures and latency — a relay that failed 5 times but recovered an hour ago still scored poorly.

New scoring formula:
- Failure penalty: `min(consecutive_failures / 10, 1.0)` → base score (unchanged)
- Recency bonus: `max(0, 1 - hoursSinceSuccess / 24)` → 20% weight (new)
- Latency factor: `max(0, 1 - avgLatencyMs / 2000)` → 30% weight (unchanged)

**Outcome:** Relays that recover from temporary issues will be re-prioritized within hours instead of being stuck at a low score until failure counters are manually reset.

#### #13 — strict_types (Implemented ✅)

**Changed:** Added `declare(strict_types=1);` to `NostrRelayPool.php`

This was the only file in the relay subsystem missing the declaration. No behavioral change since the file's public API already accepts typed parameters via the interface.

#### #9 — Unit Tests (Implemented ✅)

**Created 4 test files, 68 tests, 102 assertions:**

| File | Tests | Covers |
|------|-------|--------|
| `tests/Unit/Util/RelayUrlNormalizerTest.php` | 14 | normalize, equals, edge cases |
| `tests/Unit/Service/Nostr/RelayRegistryTest.php` | 25 | Purpose resolution, LOCAL/PROJECT dedup, isProjectRelay with trailing slash/case, ensureLocalRelayInList, fallbacks, getAllUrls uniqueness |
| `tests/Unit/Service/Nostr/RelayHealthStoreTest.php` | 14 | Health defaults, data parsing, score with no failures, score degradation, muted=0, recency bonus, latency penalty, max failures clamping, isHealthy, URL normalization consistency |
| `tests/Unit/Service/Nostr/GatewayConnectionTest.php` | 15 | buildKey shared/user, normalization variants, isShared/isUserConnection, auth status, touch, exponential backoff |

All tests run inside Docker via `bin/phpunit`. Full existing unit suite verified: 12 pre-existing errors + 1 pre-existing failure unrelated to these changes.

#### #6 — Remove Redundant Constructor Parameter (Implemented ✅)

**Changed:** `RelayAdminService`

Removed `?string $nostrDefaultRelay = null` from the constructor. Added a private `getLocalRelayUrl()` helper that delegates to `$this->relayRegistry->getLocalRelay()` with a `DEFAULT_LOCAL_RELAY` constant (`ws://strfry:7777`) as fallback. All five previous `$this->nostrDefaultRelay ?? 'ws://strfry:7777'` call sites now use the helper. `isLocalRelay()` updated to call `$this->relayRegistry->getLocalRelay()` directly.

The global `$nostrDefaultRelay` bind in `services.yaml` is kept because many other services still use it.

#### #11 — Hardcoded Relay URL in Stimulus Controller (Implemented ✅)

**Changed:** `nostr_settings_relays_controller.js` + `_relays.html.twig` + `SettingsController`

Replaced the hardcoded `'wss://relay.decentnewsroom.com'` in `addHomeRelay()` with `this.homeRelayValue`, a new Stimulus value. The backend injects it via `$this->relayRegistry->getPublicUrl()` and the Twig template passes it as `data-nostr--nostr-settings-relays-home-relay-value`. The URL now stays in sync with `relay_registry.project_relays` in `services.yaml`.

#### #14 — Health TTL Extended for Configured Relays (Implemented ✅)

**Changed:** `RelayHealthStore`

Added `CONFIGURED_TTL = 604800` (7 days) alongside the existing `TTL = 86400` (24 hours). A new `ttlFor(string $relayUrl)` method checks `$this->relayRegistry->isConfiguredRelay($url)` to pick the correct TTL. All six `expire()` calls in the writer methods now use `$this->ttlFor($relayUrl)` instead of the flat `self::TTL`.

**Why:** Configured relays (project, profile, local) are always refreshed by workers and cron jobs, so a 7-day TTL prevents silent data loss during low-activity weekends. Ad-hoc user relays keep 24-hour expiry to avoid unbounded growth.

`RelayHealthStore` constructor now requires a `RelayRegistry` parameter. Updated `RelayHealthStoreTest` setUp and inline instantiations to pass a mock.

### Remaining Items (Not Yet Addressed)

| # | Issue | Status |
|---|-------|--------|
| 8 | No circuit breaker | Open |
| 10 | Gateway monolith | Open — high effort |
| 12 | Admin bypasses pool | Open |

### Actions Taken (2026-03-31, second pass)

#### #15 — Relay Limit Truncation (Resolved ✅)

**Changed:** `UserRelayListService::getRelaysForUser()`, `getRelaysForFetching()`, `getRelaysForPublishing()`

Removed the `$limit` parameter and `array_slice()` truncation from all three methods. Previously a hard `$limit = 5` silently dropped user relays and registry defaults when the combined list exceeded 5 entries — if a user had 10 relays and the local relay plus 4 user relays filled the cap, no registry defaults were included.

Now all available relays are returned, with priority preserved:
1. Local relay (always first — it ingests from multiple upstreams)
2. User's own relays (from NIP-65 kind 10002 relay list)
3. Registry defaults for the requested purpose

Callers that previously passed explicit limits (`EventController` with `4`, `FetchEventFromRelaysHandler` with `5`) have been updated to use the no-limit signature.

**Rationale:** The local relay already aggregates content from multiple upstream relays, so it naturally covers a wide range. Including all user relays ensures content published to less common relays is not missed. The pool/connection layer handles parallelism — the relay list service should not second-guess how many relays to query.

#### #3 — `isValidRelay()` Edge Cases (Resolved ✅)

**Changed:** `UserRelayListService::isValidRelay()`

Replaced the naive validation:
```php
return str_starts_with($url, 'wss://') && !str_contains($url, 'localhost');
```

With a proper implementation that:
- Normalizes the URL via `RelayUrlNormalizer::normalize()` before checking
- Uses `filter_var(FILTER_VALIDATE_URL)` for structural validation
- Parses the hostname with `parse_url()` and rejects only `localhost` and `127.0.0.1` as the actual host — not as a substring anywhere in the URL

This fixes two edge cases:
1. A URL like `wss://relay.localhost.com` was incorrectly rejected (unlikely but technically valid)
2. URLs with whitespace or inconsistent casing were not normalized before validation

#### #7 — Synthetic Event IDs (Resolved ✅)

**Changed:** `UserRelayListService`, `FollowsRelayPoolService`, `EventRepository`, new `UserRelayList` entity + `UserRelayListRepository`

The core problem: `UserRelayListService::persistToDatabase()` created fake "events" in the `event` table with IDs like `relay_list_{hex}_{timestamp}`, kind 10002, and empty signatures. These are not Nostr events — they're server-cached relay list data that was shoehorned into the event schema.

**Solution:** Created a dedicated `UserRelayList` entity (`src/Entity/UserRelayList.php`) with proper domain columns:
- `pubkey` (unique, indexed) — hex pubkey
- `read_relays` (JSONB) — array of read relay URLs
- `write_relays` (JSONB) — array of write relay URLs
- `created_at` (BIGINT) — Nostr timestamp from the original kind 10002 event
- `updated_at` (DATETIME) — server timestamp of last upsert

Changes made:
- `UserRelayListService`: replaced `EventRepository` dependency with `UserRelayListRepository`. `fromDatabase()` reads directly from entity getters. `persistToDatabase()` upserts a `UserRelayList` row instead of creating a synthetic Event. Removed `parseRelayListEvent()` helper.
- `FollowsRelayPoolService`: added `UserRelayListRepository` dependency. `buildPool()` reads `getWriteRelays()` directly instead of parsing NIP-65 `r`-tags from Event tags.
- `EventRepository`: removed `findLatestRelayListByPubkey()` and `findLatestRelayListsByPubkeys()` — both replaced by repository methods on `UserRelayListRepository`.
- Migration `Version20260331120000`: creates the table, seeds from existing synthetic events (parsing JSONB tags via PostgreSQL functions), and deletes synthetic events from the event table.

**The Settings relay tab** (`SettingsController::buildRelayListFromEvent`) is unaffected — it reads *real* kind 10002 events from the event table (persisted by relay subscription workers), not synthetic ones.

#### Follows Relay Pool Integration into `getRelaysForUser()` (Implemented ✅)

**Changed:** `UserRelayListService`

Injected `FollowsRelayPoolService` as an optional dependency into `UserRelayListService`. When `getRelaysForUser()` is called with `CONTENT` purpose, the follows relay pool is now merged into the returned relay set between user relays and registry defaults.

New priority order:
1. Local relay (always first — ingests from multiple upstreams)
2. User's own NIP-65 relays (from kind 10002 relay list)
3. **Follows relay pool** — deduplicated write relays of all followed authors (new)
4. Registry defaults for the requested purpose

The follows pool comes from `FollowsRelayPoolService::getPoolForUser()`, which is cached in Redis with a 30-day TTL keyed to the kind 3 (follows) event ID. The pool only rebuilds when the follows list changes, so the lookup is a cheap Redis read + one indexed DB query.

Design decisions:
- **CONTENT only:** The follows pool is only included for `RelayPurpose::CONTENT`. For PROFILE and publishing, follows' relays are irrelevant.
- **Nullable dependency:** `FollowsRelayPoolService` is nullable to avoid breaking existing callers or services that don't need the follows pool.
- **Error resilience:** The follows pool lookup is wrapped in a try/catch — if Redis or the DB is down, the method falls through gracefully to registry defaults.
- **Project relay filtering:** Follows pool relays that match the project relay are skipped when a local relay is present (same LOCAL/PROJECT dedup as user relays).
- **Deduplication:** All relays are deduplicated by exact URL match across all layers.

Added 8 unit tests in `UserRelayListServiceTest`:
- `testGetRelaysForUserContentIncludesFollowsPool` — basic integration
- `testGetRelaysForUserContentDeduplicatesFollowsPool` — no duplicates across layers
- `testGetRelaysForUserProfileDoesNotIncludeFollowsPool` — PROFILE purpose skips pool
- `testGetRelaysForUserContentWorksWithoutFollowsService` — null service is safe
- `testGetRelaysForUserContentFollowsPoolExceptionIsSwallowed` — error resilience
- `testGetRelaysForUserContentFollowsPoolSkipsProjectRelay` — project relay filtered
- `testGetRelaysForUserContentFollowsPoolEmptyReturnsNormally` — empty pool is a no-op
- `testGetRelaysForUserContentPriorityOrder` — verifies local → user → follows → registry ordering

#### #2 — Pool Duplicates Registry (Resolved ✅)

**Changed:** `NostrRelayPool`, `RelayPoolInterface`, and 8 callers across 7 files

`NostrRelayPool` had four public methods that directly duplicated `RelayRegistry` functionality:
- `getLocalRelay()` — same as `RelayRegistry::getLocalRelay()`
- `ensureLocalRelayInList()` — same as `RelayRegistry::ensureLocalRelayInList()`
- `getRelayRegistry()` — leaky abstraction exposing the inner registry
- `getHealthStore()` — leaky abstraction exposing the inner health store

Additionally, `normalizeRelayUrl()` was public but just delegated to `RelayUrlNormalizer::normalize()`.

**Removed from `NostrRelayPool`:**
- `getLocalRelay()` (was public, removed)
- `ensureLocalRelayInList()` (was public, removed)
- `getRelayRegistry()` (was public, removed)
- `getHealthStore()` (was public, removed)
- `normalizeRelayUrl()` (changed from public to private — still used internally)

**Removed from `RelayPoolInterface`:**
- `getLocalRelay()` — relay URL resolution is not a pool concern

**Migrated callers (8 call sites across 7 files):**

| File | Change |
|------|--------|
| `NostrRelayPoolStatsCommand` | Already had `RelayRegistry` — switched `relayPool->getLocalRelay()` |
| `SubscribeLocalMagazinesCommand` | Injected `RelayRegistry`, switched `getLocalRelay()` |
| `SubscribeLocalRelayCommand` | Injected `RelayRegistry`, switched `getLocalRelay()` |
| `SubscribeLocalUserContextCommand` | Injected `RelayRegistry`, switched `getLocalRelay()` |
| `SubscribeLocalMediaCommand` | Injected `RelayRegistry`, switched `getLocalRelay()` |
| `BackfillProfilesCommand` | Injected `RelayRegistry`, switched `getLocalRelay()` |
| `SyncUserEventsHandler` | Injected `RelayRegistry`, switched `getLocalRelay()` |
| `RelayAdminService` | Already had `RelayRegistry` — switched `relayPool->getLocalRelay()` |
| `NostrRequestExecutor` | Injected `RelayRegistry`, switched `getRelayRegistry()` and `normalizeRelayUrl()` → `RelayUrlNormalizer::normalize()` |

**Design outcome:** `NostrRelayPool` is now a pure connection manager — it opens, closes, reuses WebSocket connections and records health metrics. All relay URL selection, purpose-based resolution, and URL normalization lives in `RelayRegistry` and `RelayUrlNormalizer`. The pool's internal use of `$nostrDefaultRelay` for connection management (local relay prioritization, partitioning) is unchanged.

