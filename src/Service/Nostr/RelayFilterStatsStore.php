<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed per-relay filter shape statistics.
 *
 * For every REQ the gateway sends to a relay we record the *shape* of the
 * filter (kinds, presence/count of authors/ids/tag keys, since/until, limit)
 * so we can later answer:
 *
 *   1. "What filters do I typically send?"   (top filter signatures by count)
 *   2. "Which take long to resolve?"          (top filter signatures by avg/max latency)
 *
 * Privacy: the signature deliberately never contains author hex pubkeys, event
 * ids or tag values — only kinds (which are public, low-cardinality) and
 * counts. A relay operator could reconstruct kinds and shape but never which
 * users we are looking for.
 *
 * Storage:
 *   relay_filter_stats:{normalizedRelayUrl}     HASH
 *      {sig}|count            int              total REQs sent
 *      {sig}|eose_count       int              REQs that completed via EOSE
 *      {sig}|timeout_count    int              REQs that timed out / closed
 *      {sig}|sum_ms           float            sum of EOSE latencies (ms)
 *      {sig}|max_ms           int              slowest EOSE latency (ms)
 *      {sig}|last_ms          int              most recent EOSE latency (ms)
 *      {sig}|last_at          int              unix ts of most recent EOSE
 *      {sig}|events           int              total events received under this signature
 *
 *   relay_filter_stats:_index                   SET    of normalized relay URLs seen
 *
 * TTL mirrors RelayHealthStore: 7 days for configured relays, 24 h otherwise.
 */
class RelayFilterStatsStore
{
    private const KEY_PREFIX = 'relay_filter_stats:';
    private const INDEX_KEY = 'relay_filter_stats:_index';
    private const TTL = 86400;
    private const CONFIGURED_TTL = 604800;

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
        private readonly RelayRegistry $relayRegistry,
    ) {}

    /**
     * Build a privacy-safe signature describing the filter shape.
     *
     * Examples:
     *   kinds=[30023];authors=N12;limit=20;since
     *   kinds=[1,1111];#e=1
     *   kinds=[0];authors=N50
     *
     * @param array<string,mixed> $filter
     */
    public function signature(array $filter): string
    {
        $parts = [];

        // Kinds — keep them, low cardinality and public
        if (isset($filter['kinds']) && is_array($filter['kinds'])) {
            $kinds = array_values(array_unique(array_map('intval', $filter['kinds'])));
            sort($kinds);
            $parts[] = 'kinds=[' . implode(',', $kinds) . ']';
        }

        // Authors — count only
        if (isset($filter['authors']) && is_array($filter['authors'])) {
            $parts[] = 'authors=N' . count($filter['authors']);
        }

        // Ids — count only
        if (isset($filter['ids']) && is_array($filter['ids'])) {
            $parts[] = 'ids=N' . count($filter['ids']);
        }

        // Tag filters — preserve key names (#e, #p, #t, #a, #A, #d, #k, …) + count.
        // Case is intentionally kept: #A and #a are different NIP-01 tag filters
        // that hit different relay indexes and will have different performance profiles.
        $tagKeys = [];
        foreach ($filter as $key => $value) {
            if (is_string($key) && str_starts_with($key, '#') && strlen($key) === 2 && is_array($value)) {
                $tagKeys[$key] = ($tagKeys[$key] ?? 0) + count($value);
            }
        }
        ksort($tagKeys);
        foreach ($tagKeys as $key => $count) {
            $parts[] = $key . '=N' . $count;
        }

        // Bound flags — presence only (timestamps would create unique signatures)
        if (isset($filter['since'])) {
            $parts[] = 'since';
        }
        if (isset($filter['until'])) {
            $parts[] = 'until';
        }
        if (isset($filter['limit'])) {
            // Keep the actual limit value — common limits (20, 50, 100, 500)
            // form natural buckets and are useful to the operator.
            $parts[] = 'limit=' . (int) $filter['limit'];
        }
        if (isset($filter['search']) && is_string($filter['search'])) {
            $parts[] = 'search';
        }

        return $parts === [] ? 'empty' : implode(';', $parts);
    }

    private function ttlFor(string $relayUrl): int
    {
        return $this->relayRegistry->isConfiguredRelay($relayUrl)
            ? self::CONFIGURED_TTL
            : self::TTL;
    }

    private function key(string $relayUrl): string
    {
        return self::KEY_PREFIX . RelayUrlNormalizer::normalize($relayUrl);
    }

    // ----- writers -----

    /**
     * Record that a REQ with this filter shape was sent to a relay.
     */
    public function recordRequest(string $relayUrl, string $signature): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hIncrBy($key, $signature . '|count', 1);
            $this->redis->expire($key, $this->ttlFor($relayUrl));
            $this->redis->sAdd(self::INDEX_KEY, RelayUrlNormalizer::normalize($relayUrl));
            $this->redis->expire(self::INDEX_KEY, self::CONFIGURED_TTL);
        } catch (\RedisException $e) {
            $this->logger->debug('RelayFilterStatsStore: write failed (request)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record that a REQ resolved with EOSE in $latencyMs and yielded $eventCount events.
     */
    public function recordEose(string $relayUrl, string $signature, int $latencyMs, int $eventCount): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hIncrBy($key, $signature . '|eose_count', 1);
            $this->redis->hIncrBy($key, $signature . '|events', $eventCount);
            $this->redis->hIncrByFloat($key, $signature . '|sum_ms', (float) $latencyMs);

            // Track max
            $currentMax = (int) ($this->redis->hGet($key, $signature . '|max_ms') ?: 0);
            if ($latencyMs > $currentMax) {
                $this->redis->hSet($key, $signature . '|max_ms', (string) $latencyMs);
            }
            $this->redis->hSet($key, $signature . '|last_ms', (string) $latencyMs);
            $this->redis->hSet($key, $signature . '|last_at', (string) time());

            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayFilterStatsStore: write failed (eose)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Record that a REQ for this filter timed out / was CLOSED before EOSE.
     */
    public function recordTimeout(string $relayUrl, string $signature): void
    {
        try {
            $key = $this->key($relayUrl);
            $this->redis->hIncrBy($key, $signature . '|timeout_count', 1);
            $this->redis->expire($key, $this->ttlFor($relayUrl));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayFilterStatsStore: write failed (timeout)', ['error' => $e->getMessage()]);
        }
    }

    // ----- readers -----

    /**
     * @return list<string> normalized URLs that have stats
     */
    public function getKnownRelays(): array
    {
        try {
            $members = $this->redis->sMembers(self::INDEX_KEY);
            return is_array($members) ? array_values($members) : [];
        } catch (\RedisException) {
            return [];
        }
    }

    /**
     * Get aggregated per-signature stats for a single relay.
     *
     * @return list<array{
     *     signature: string,
     *     count: int,
     *     eose_count: int,
     *     timeout_count: int,
     *     events: int,
     *     avg_ms: ?float,
     *     max_ms: ?int,
     *     last_ms: ?int,
     *     last_at: ?int
     * }>
     */
    public function getStatsForRelay(string $relayUrl): array
    {
        try {
            $raw = $this->redis->hGetAll($this->key($relayUrl));
        } catch (\RedisException) {
            return [];
        }
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        // Re-assemble: field is "{sig}|{metric}", value is scalar
        $bySig = [];
        foreach ($raw as $field => $value) {
            $sep = strrpos($field, '|');
            if ($sep === false) {
                continue;
            }
            $sig = substr($field, 0, $sep);
            $metric = substr($field, $sep + 1);
            $bySig[$sig][$metric] = $value;
        }

        $rows = [];
        foreach ($bySig as $sig => $metrics) {
            $count = (int) ($metrics['count'] ?? 0);
            $eose = (int) ($metrics['eose_count'] ?? 0);
            $sumMs = isset($metrics['sum_ms']) ? (float) $metrics['sum_ms'] : 0.0;
            $rows[] = [
                'signature'     => $sig,
                'count'         => $count,
                'eose_count'    => $eose,
                'timeout_count' => (int) ($metrics['timeout_count'] ?? 0),
                'events'        => (int) ($metrics['events'] ?? 0),
                'avg_ms'        => $eose > 0 ? round($sumMs / $eose, 1) : null,
                'max_ms'        => isset($metrics['max_ms']) ? (int) $metrics['max_ms'] : null,
                'last_ms'       => isset($metrics['last_ms']) ? (int) $metrics['last_ms'] : null,
                'last_at'       => isset($metrics['last_at']) ? (int) $metrics['last_at'] : null,
            ];
        }
        return $rows;
    }

    /**
     * Aggregate stats across every known relay. Returns one row per signature
     * with global counts and a weighted average latency.
     *
     * @return list<array{
     *     signature: string,
     *     count: int,
     *     eose_count: int,
     *     timeout_count: int,
     *     events: int,
     *     avg_ms: ?float,
     *     max_ms: ?int,
     *     relays: int
     * }>
     */
    public function getGlobalStats(): array
    {
        $agg = [];
        foreach ($this->getKnownRelays() as $relayUrl) {
            foreach ($this->getStatsForRelay($relayUrl) as $row) {
                $sig = $row['signature'];
                if (!isset($agg[$sig])) {
                    $agg[$sig] = [
                        'signature'     => $sig,
                        'count'         => 0,
                        'eose_count'    => 0,
                        'timeout_count' => 0,
                        'events'        => 0,
                        '_sum_ms'       => 0.0,
                        'max_ms'        => null,
                        'relays'        => 0,
                    ];
                }
                $agg[$sig]['count']         += $row['count'];
                $agg[$sig]['eose_count']    += $row['eose_count'];
                $agg[$sig]['timeout_count'] += $row['timeout_count'];
                $agg[$sig]['events']        += $row['events'];
                if ($row['avg_ms'] !== null && $row['eose_count'] > 0) {
                    $agg[$sig]['_sum_ms'] += $row['avg_ms'] * $row['eose_count'];
                }
                if ($row['max_ms'] !== null && ($agg[$sig]['max_ms'] === null || $row['max_ms'] > $agg[$sig]['max_ms'])) {
                    $agg[$sig]['max_ms'] = $row['max_ms'];
                }
                $agg[$sig]['relays']++;
            }
        }

        $rows = [];
        foreach ($agg as $r) {
            $eose = $r['eose_count'];
            $rows[] = [
                'signature'     => $r['signature'],
                'count'         => $r['count'],
                'eose_count'    => $eose,
                'timeout_count' => $r['timeout_count'],
                'events'        => $r['events'],
                'avg_ms'        => $eose > 0 ? round($r['_sum_ms'] / $eose, 1) : null,
                'max_ms'        => $r['max_ms'],
                'relays'        => $r['relays'],
            ];
        }
        return $rows;
    }

    /**
     * Clear all filter stats (for tests / admin reset).
     */
    public function clear(): void
    {
        try {
            foreach ($this->getKnownRelays() as $relayUrl) {
                $this->redis->del(self::KEY_PREFIX . $relayUrl);
            }
            $this->redis->del(self::INDEX_KEY);
        } catch (\RedisException) {
            // ignore
        }
    }
}

