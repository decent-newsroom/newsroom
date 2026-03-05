<?php

declare(strict_types=1);

namespace App\Service\Nostr;

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
    private const TTL = 86400; // 24 hours

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

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

            $this->redis->expire($key, self::TTL);
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
            $this->redis->expire($key, self::TTL);
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (failure)', ['error' => $e->getMessage()]);
        }
    }

    public function recordEventReceived(string $relayUrl): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'last_event_received', (string) time());
            $this->redis->expire($key, self::TTL);
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (event received)', ['error' => $e->getMessage()]);
        }
    }

    public function recordHeartbeat(string $relayUrl, string $workerName): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'heartbeat_' . $workerName, (string) time());
            $this->redis->expire($key, self::TTL);
        } catch (\RedisException $e) {
            $this->logger->debug('RelayHealthStore: Redis write failed (heartbeat)', ['error' => $e->getMessage()]);
        }
    }

    public function setAuthRequired(string $relayUrl, bool $required = true): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hSet($key, 'auth_required', $required ? '1' : '0');
            $this->redis->expire($key, self::TTL);
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
            $this->redis->expire($key, self::TTL);
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
     * A relay is unhealthy if it has 3+ consecutive failures.
     */
    public function isHealthy(string $relayUrl, int $maxConsecutiveFailures = 3): bool
    {
        $health = $this->getHealth($relayUrl);
        return $health['consecutive_failures'] < $maxConsecutiveFailures;
    }

    /**
     * Compute a simple health score (0.0 to 1.0).
     * Higher is better. Considers failure rate and latency.
     */
    public function getHealthScore(string $relayUrl): float
    {
        $health = $this->getHealth($relayUrl);

        // Base score: penalize consecutive failures
        $failurePenalty = min($health['consecutive_failures'] / 10.0, 1.0);
        $score = 1.0 - $failurePenalty;

        // Latency bonus/penalty (normalize: 0-500ms is good, >2000ms is bad)
        if ($health['avg_latency_ms'] !== null) {
            $latencyFactor = max(0.0, 1.0 - ($health['avg_latency_ms'] / 2000.0));
            $score = ($score * 0.7) + ($latencyFactor * 0.3);
        }

        return max(0.0, min(1.0, $score));
    }

    // ----- internals -----

    private function key(string $relayUrl): string
    {
        // Normalize: trim, remove trailing slash
        $url = rtrim(trim($relayUrl), '/');
        return self::KEY_PREFIX . $url;
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

