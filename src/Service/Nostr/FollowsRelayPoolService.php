<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;

/**
 * Builds and caches a per-user consolidated relay pool from all followed
 * authors' declared write relays (kind 10002).
 *
 * The pool is cached in Redis with a 30-day TTL, keyed to the kind 3
 * (follows list) event ID. It only rebuilds when the follows list
 * actually changes (new event ID from a follow/unfollow).
 *
 * On each access a single indexed DB lookup validates the cache by
 * comparing the stored follows_event_id against the current kind 3
 * event ID.
 *
 * @see documentation/Nostr/fetch-optimization-plan.md — Phase 4
 */
class FollowsRelayPoolService
{
    private const REDIS_PREFIX = 'follows_relay_pool:';
    private const TTL = 2592000; // 30 days
    private const MAX_POOL_SIZE = 25; // cap to keep the pool manageable

    public function __construct(
        private readonly \Redis               $redis,
        private readonly EventRepository      $eventRepository,
        private readonly RelayHealthStore     $healthStore,
        private readonly LoggerInterface      $logger,
    ) {}

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Get the consolidated relay pool for a user's follows feed.
     *
     * 1. Read cached pool from Redis
     * 2. Look up the current kind 3 event ID from the DB (cheap indexed query)
     * 3. If the cached follows_event_id matches → return cached relays
     * 4. If mismatch or miss → rebuild, cache, return
     *
     * @return string[] Deduplicated, health-ranked relay URLs
     */
    public function getPoolForUser(string $userPubkey): array
    {
        // Current follows event from DB
        $followsEvent = $this->eventRepository->findLatestByPubkeyAndKind(
            $userPubkey,
            KindsEnum::FOLLOWS->value
        );

        if ($followsEvent === null) {
            $this->logger->debug('FollowsRelayPoolService: no follows event in DB', [
                'pubkey' => substr($userPubkey, 0, 8) . '...',
            ]);
            return [];
        }

        $currentEventId = $followsEvent->getId();

        // Try cache
        $cached = $this->readCache($userPubkey);
        if ($cached !== null && ($cached['follows_event_id'] ?? '') === $currentEventId) {
            $this->logger->debug('FollowsRelayPoolService: cache hit (event ID match)', [
                'pubkey' => substr($userPubkey, 0, 8) . '...',
                'relays' => count($cached['relays'] ?? []),
            ]);
            return $cached['relays'] ?? [];
        }

        // Cache miss or stale — rebuild
        $this->logger->info('FollowsRelayPoolService: cache miss or event ID changed, rebuilding', [
            'pubkey'          => substr($userPubkey, 0, 8) . '...',
            'cached_event_id' => substr($cached['follows_event_id'] ?? 'none', 0, 16),
            'current_event_id' => substr($currentEventId, 0, 16),
        ]);

        return $this->buildPool($userPubkey, $followsEvent);
    }

    /**
     * Proactively build the pool (called from the Messenger handler).
     */
    public function warmPool(string $userPubkey): void
    {
        $followsEvent = $this->eventRepository->findLatestByPubkeyAndKind(
            $userPubkey,
            KindsEnum::FOLLOWS->value
        );

        if ($followsEvent === null) {
            $this->logger->info('FollowsRelayPoolService::warmPool: no follows event, skipping', [
                'pubkey' => substr($userPubkey, 0, 8) . '...',
            ]);
            return;
        }

        // Check if cache is already valid
        $cached = $this->readCache($userPubkey);
        if ($cached !== null && ($cached['follows_event_id'] ?? '') === $followsEvent->getId()) {
            $this->logger->debug('FollowsRelayPoolService::warmPool: already up to date', [
                'pubkey' => substr($userPubkey, 0, 8) . '...',
            ]);
            return;
        }

        $this->buildPool($userPubkey, $followsEvent);
    }

    /**
     * Explicitly invalidate the cached pool.
     */
    public function invalidate(string $userPubkey): void
    {
        try {
            $this->redis->del(self::REDIS_PREFIX . $userPubkey);
            $this->logger->debug('FollowsRelayPoolService: cache invalidated', [
                'pubkey' => substr($userPubkey, 0, 8) . '...',
            ]);
        } catch (\RedisException $e) {
            $this->logger->warning('FollowsRelayPoolService: invalidation failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Pool builder
    // ------------------------------------------------------------------

    /**
     * @param \App\Entity\Event $followsEvent The user's kind 3 event
     * @return string[] The built relay pool
     */
    private function buildPool(string $userPubkey, \App\Entity\Event $followsEvent): array
    {
        $startTime = microtime(true);

        // 1. Extract followed pubkeys from p-tags
        $followedPubkeys = [];
        foreach ($followsEvent->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === 'p' && isset($tag[1]) && strlen($tag[1]) === 64) {
                $followedPubkeys[] = $tag[1];
            }
        }
        $followedPubkeys = array_values(array_unique($followedPubkeys));

        if (empty($followedPubkeys)) {
            $this->logger->info('FollowsRelayPoolService: follows list is empty', [
                'pubkey' => substr($userPubkey, 0, 8) . '...',
            ]);
            $this->writeCache($userPubkey, [], $followsEvent->getId(), 0, 0);
            return [];
        }

        $this->logger->info('FollowsRelayPoolService: building pool', [
            'pubkey'        => substr($userPubkey, 0, 8) . '...',
            'follows_count' => count($followedPubkeys),
        ]);

        // 2. Batch-resolve relay lists from DB
        $relayListEvents = $this->eventRepository->findLatestRelayListsByPubkeys($followedPubkeys);
        $coverage = count($relayListEvents);

        // 3. Extract write relay URLs from all resolved relay lists
        $relayUrls = [];
        foreach ($relayListEvents as $pubkey => $event) {
            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || ($tag[0] ?? '') !== 'r' || empty($tag[1])) {
                    continue;
                }
                $url = $tag[1];
                $marker = $tag[2] ?? null;

                // Include write relays and unmarked relays (unmarked = both read+write)
                if ($marker === null || $marker === 'write') {
                    $relayUrls[] = $url;
                }
            }
        }

        // 4. Deduplicate
        $relayUrls = array_values(array_unique($relayUrls));

        // 5. Filter invalid URLs
        $relayUrls = array_values(array_filter($relayUrls, function (string $url): bool {
            return str_starts_with($url, 'wss://') && !str_contains($url, 'localhost');
        }));

        // 6. Sort by health score (best first)
        usort($relayUrls, function (string $a, string $b): int {
            $scoreA = $this->healthStore->getHealthScore($a);
            $scoreB = $this->healthStore->getHealthScore($b);
            return $scoreB <=> $scoreA; // descending
        });

        // 7. Cap at max pool size
        $pool = array_slice($relayUrls, 0, self::MAX_POOL_SIZE);

        // 8. Cache
        $this->writeCache($userPubkey, $pool, $followsEvent->getId(), count($followedPubkeys), $coverage);

        $elapsed = round((microtime(true) - $startTime) * 1000, 1);

        $this->logger->info('FollowsRelayPoolService: pool built', [
            'pubkey'             => substr($userPubkey, 0, 8) . '...',
            'follows_count'      => count($followedPubkeys),
            'relay_list_coverage' => $coverage,
            'unique_relays_found' => count($relayUrls),
            'pool_size'          => count($pool),
            'elapsed_ms'         => $elapsed,
        ]);

        return $pool;
    }

    // ------------------------------------------------------------------
    // Redis I/O
    // ------------------------------------------------------------------

    private function readCache(string $userPubkey): ?array
    {
        try {
            $raw = $this->redis->get(self::REDIS_PREFIX . $userPubkey);
            if ($raw === false || $raw === null) {
                return null;
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        } catch (\RedisException $e) {
            $this->logger->debug('FollowsRelayPoolService: Redis read failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function writeCache(
        string $userPubkey,
        array  $relays,
        string $followsEventId,
        int    $followsCount,
        int    $relayListCoverage,
    ): void {
        try {
            $payload = json_encode([
                'relays'              => $relays,
                'follows_event_id'    => $followsEventId,
                'built_at'            => time(),
                'follows_count'       => $followsCount,
                'relay_list_coverage' => $relayListCoverage,
            ], JSON_THROW_ON_ERROR);

            $this->redis->setex(self::REDIS_PREFIX . $userPubkey, self::TTL, $payload);
        } catch (\Throwable $e) {
            $this->logger->warning('FollowsRelayPoolService: Redis write failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}


