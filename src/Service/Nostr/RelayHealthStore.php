<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed persistent health tracking for Nostr relays.
 *
 * Stores per-relay health metrics in Redis so they survive PHP worker restarts
 * and are shared across all FrankenPHP workers:
 *
 *   - last_success / last_failure — timestamps
 *   - consecutive_failures        — int
 *   - avg_latency_ms              — rolling average
 *   - auth_required               — bool
 *   - auth_status                 — none | ephemeral | user_authed | pending | failed
 *   - last_event_received         — timestamp (written by subscription workers)
 *
 * Written by NostrRelayPool::sendToRelays(), TweakedRequest::send(),
 * and subscription loops. Read by admin dashboard and health-based ranking.
 */
class RelayHealthStore
{
    private const KEY_PREFIX = 'relay_health:';
    private const TTL = 86400; // 24 hours — ad-hoc user relays
    private const CONFIGURED_TTL = 604800; // 7 days — relays in the registry

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        private readonly RelayRegistry $relayRegistry,
    ) {}

    /**
     * Configured (project/profile/local) relays get a 7-day TTL so their
     * health history is not silently lost between worker interactions.
     * Ad-hoc user relays keep the standard 24-hour window.
     */
    private function ttlFor(string $relayUrl): int
    {
        return $this->relayRegistry->isConfiguredRelay($relayUrl)
            ? self::CONFIGURED_TTL
            : self::TTL;
    }

    // ----- writers -----

    public function recordSuccess(string $relayUrl, ?int $latencyMs = null): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'last_success', (string) time());
            $this->redis->hSet($key, 'consecutive_failures', '0');

            if ($latencyMs !== null) {
                $this->updateLatency($key, $latencyMs);
            }

            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (success)', ['error' => $e->getMessage()]);
        }
    }

    public function recordFailure(string $relayUrl): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'last_failure', (string) time());
            $this->redis->hIncrBy($key, 'consecutive_failures', 1);
            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (failure)', ['error' => $e->getMessage()]);
        }
    }

    public function recordEventReceived(string $relayUrl): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'last_event_received', (string) time());
            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (event received)', ['error' => $e->getMessage()]);
        }
    }

    public function recordHeartbeat(string $relayUrl, string $workerName): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'heartbeat_' . $workerName, (string) time());
            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (heartbeat)', ['error' => $e->getMessage()]);
        }
    }

    public function setAuthRequired(string $relayUrl, bool $required = true): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'auth_required', $required ? '1' : '0');
            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (auth_required)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Check if a relay is known to require AUTH.
     */
    public function isAuthRequired(string $relayUrl): bool
    {
        $health = $this->getHealth($relayUrl);
        return $health['auth_required'];
    }

    /**
     * @param string $status One of: none, ephemeral, user_authed, pending, failed
     */
    public function setAuthStatus(string $relayUrl, string $status): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'auth_status', $status);
            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (auth_status)', ['error' => $e->getMessage()]);
        }
    }

    // ----- readers -----

    /**
     * Get all health data for a single relay.
     *
     * @return array{
     *     last_success: ?int,
     *     last_failure: ?int,
     *     consecutive_failures: int,
     *     avg_latency_ms: ?float,
     *     auth_required: bool,
     *     auth_status: string,
     *     last_event_received: ?int,
     *     heartbeats: array<string, int>
     * }
     */
    public function getHealth(string $relayUrl): array
    {
        $defaults = [
            'last_success' => null,
            'last_failure' => null,
            'consecutive_failures' => 0,
            'avg_latency_ms' => null,
            'auth_required' => false,
            'auth_status' => 'none',
            'last_event_received' => null,
            'heartbeats' => [],
        ];

        try {
            $raw = $this->redis->hGetAll($this->key($relayUrl));
            if (!$raw) {
                return $defaults;
            }

            $heartbeats = [];
            foreach ($raw as $field => $value) {
                if (str_starts_with($field, 'heartbeat_')) {
                    $heartbeats[substr($field, 10)] = (int) $value;
                }
            }

            return [
                'last_success' => isset($raw['last_success']) ? (int) $raw['last_success'] : null,
                'last_failure' => isset($raw['last_failure']) ? (int) $raw['last_failure'] : null,
                'consecutive_failures' => (int) ($raw['consecutive_failures'] ?? 0),
                'avg_latency_ms' => isset($raw['avg_latency_ms']) ? (float) $raw['avg_latency_ms'] : null,
                'auth_required' => ($raw['auth_required'] ?? '0') === '1',
                'auth_status' => $raw['auth_status'] ?? 'none',
                'last_event_received' => isset($raw['last_event_received']) ? (int) $raw['last_event_received'] : null,
                'heartbeats' => $heartbeats,
            ];
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis read failed', ['error' => $e->getMessage()]);
            return $defaults;
        }
    }

    /**
     * Get health data for all known relays.
     *
     * @param string[] $relayUrls
     * @return array<string, array> URL => health data
     */
    public function getHealthForRelays(array $relayUrls): array
    {
        $result = [];
        foreach ($relayUrls as $url) {
            $result[$url] = $this->getHealth($url);
        }
        return $result;
    }

    /**
     * Check if a relay is considered healthy.
     * A relay is unhealthy if it has 3+ consecutive failures or is muted.
     */
    public function isHealthy(string $relayUrl, int $maxConsecutiveFailures = 3): bool
    {
        if ($this->isMuted($relayUrl)) {
            return false;
        }

        $health = $this->getHealth($relayUrl);
        return $health['consecutive_failures'] < $maxConsecutiveFailures;
    }

    /**
     * Compute a health score (0.0 to 1.0).
     * Higher is better. Considers failure rate, recency of last success, and latency.
     * Returns 0 for muted relays.
     *
     * Scoring components (weighted blend):
     *   - Failure penalty:  consecutive failures / 10, capped at 1.0           (dominant)
     *   - Recency bonus:    decays linearly over 24 h since last success       (20% weight)
     *   - Latency factor:   0–500 ms is good, >2000 ms is bad                 (30% weight)
     *
     * The recency bonus ensures that relays which have recovered from a failure
     * burst are not penalized indefinitely — as soon as a new success is
     * recorded the score starts climbing back.
     */
    public function getHealthScore(string $relayUrl): float
    {
        if ($this->isMuted($relayUrl)) {
            return 0.0;
        }
        $health = $this->getHealth($relayUrl);

        // Base score: penalize consecutive failures
        $failurePenalty = min($health['consecutive_failures'] / 10.0, 1.0);
        $score = 1.0 - $failurePenalty;

        // Recency bonus: relays that succeeded recently get a boost
        if ($health['last_success'] !== null) {
            $hoursSinceSuccess = (time() - $health['last_success']) / 3600.0;
            $recencyFactor = max(0.0, 1.0 - ($hoursSinceSuccess / 24.0)); // decays over 24h
            $score = ($score * 0.8) + ($recencyFactor * 0.2);
        }

        // Latency bonus/penalty (normalize: 0-500ms is good, >2000ms is bad)
        if ($health['avg_latency_ms'] !== null) {
            $latencyFactor = max(0.0, 1.0 - ($health['avg_latency_ms'] / 2000.0));
            $score = ($score * 0.7) + ($latencyFactor * 0.3);
        }

        return max(0.0, min(1.0, $score));
    }

    // ----- muting -----

    private const MUTED_SET = 'relay_muted_urls';

    /**
     * Mute a relay — it will be excluded from relay operations.
     */
    public function muteRelay(string $relayUrl): void
    {
        try {
            $this->redis->sAdd(self::MUTED_SET, RelayUrlNormalizer::normalize($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->warning('RelayHealthStore: failed to mute relay', ['url' => $relayUrl, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Unmute a relay — it will be included in relay operations again.
     */
    public function unmuteRelay(string $relayUrl): void
    {
        try {
            $this->redis->sRem(self::MUTED_SET, RelayUrlNormalizer::normalize($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->warning('RelayHealthStore: failed to unmute relay', ['url' => $relayUrl, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Check if a relay is muted.
     */
    public function isMuted(string $relayUrl): bool
    {
        try {
            return (bool) $this->redis->sIsMember(self::MUTED_SET, RelayUrlNormalizer::normalize($relayUrl));
        } catch (\RedisException $e) {
            return false;
        }
    }

    /**
     * Get all muted relay URLs.
     *
     * @return string[]
     */
    public function getMutedRelays(): array
    {
        try {
            $members = $this->redis->sMembers(self::MUTED_SET);
            return is_array($members) ? $members : [];
        } catch (\RedisException $e) {
            return [];
        }
    }

    /**
     * Get ALL relay URLs that have health data in Redis (relay_health:* keys).
     * This includes relays from user relay lists, not just configured ones.
     *
     * Uses SCAN instead of KEYS to avoid blocking Redis when the key space is
     * large (KEYS is O(N) and holds the Redis lock for the entire duration).
     *
     * @return string[]
     */
    public function getAllKnownRelayUrls(): array
    {
        try {
            $urls = [];
            $iterator = null;
            $pattern = self::KEY_PREFIX . '*';
            $prefixLen = strlen(self::KEY_PREFIX);

            // phpredis SCAN: $iterator is passed by reference, loop while it
            // returns non-false. When the full scan is complete $iterator becomes 0.
            do {
                $keys = $this->redis->scan($iterator, $pattern, 100);
                if ($keys !== false) {
                    foreach ($keys as $key) {
                        $urls[] = substr($key, $prefixLen);
                    }
                }
            } while ($iterator > 0);

            return $urls;
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: failed to scan relay_health keys', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ----- internals -----

    private function key(string $relayUrl): string
    {
        return self::KEY_PREFIX . RelayUrlNormalizer::normalize($relayUrl);
    }

    private function updateLatency(string $key, int $latencyMs): void
    {
        $current = $this->redis->hGet($key, 'avg_latency_ms');
        if ($current !== false && $current !== '') {
            // Exponential moving average (alpha = 0.3)
            $avg = (float) $current;
            $avg = ($avg * 0.7) + ($latencyMs * 0.3);
        } else {
            $avg = (float) $latencyMs;
        }
        $this->redis->hSet($key, 'avg_latency_ms', (string) round($avg, 1));
    }
}

