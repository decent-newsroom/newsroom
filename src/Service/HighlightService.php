<?php

namespace App\Service;

use App\Entity\Highlight;
use App\Repository\HighlightRepository;
use App\Service\Nostr\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class HighlightService
{
    private const CACHE_DURATION_HOURS = 24; // Redis metadata TTL
    private const REDIS_TTL = 600; // 10 minutes Redis cache for highlight results
    private const REDIS_PREFIX = 'highlights:article:';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HighlightRepository $highlightRepository,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
        private readonly \Redis $redis,
    ) {}

    /**
     * Get highlights for an article, using Redis → DB cache layers.
     * Redis serves the hot path; DB queries only run on Redis miss.
     *
     * No relay refresh is triggered during the web request — highlights are
     * ingested asynchronously by cron (app:fetch-highlights,
     * app:cache-latest-highlights) and relay subscription workers.
     */
    public function getHighlightsForArticle(string $articleCoordinate): array
    {
        // Fast path: check Redis first
        $redisKey = self::REDIS_PREFIX . md5($articleCoordinate);
        try {
            $cached = $this->redis->get($redisKey);
            if ($cached !== false) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    $this->logger->debug('Highlights served from Redis', [
                        'coordinate' => $articleCoordinate,
                        'count' => count($decoded),
                    ]);
                    return $decoded;
                }
            }
        } catch (\RedisException $e) {
            $this->logger->debug('Redis read failed for highlights, falling back to DB', [
                'error' => $e->getMessage(),
            ]);
        }

        // Slow path: load from DB (no relay refresh dispatch)
        $highlights = $this->getCachedHighlights($articleCoordinate);

        // Warm Redis for subsequent requests
        $this->storeInRedis($redisKey, $highlights);

        return $highlights;
    }


    /**
     * Store highlight results in Redis and update metadata.
     */
    private function storeInRedis(string $redisKey, array $highlights): void
    {
        try {
            $this->redis->setex($redisKey, self::REDIS_TTL, json_encode($highlights));

            // Store metadata for staleness checks
            $metaKey = $redisKey . ':meta';
            $this->redis->hSet($metaKey, 'last_refresh', (string) time());
            $this->redis->expire($metaKey, self::CACHE_DURATION_HOURS * 3600);
        } catch (\RedisException $e) {
            $this->logger->debug('Redis write failed for highlights', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Invalidate Redis cache for an article's highlights.
     * Called after saving new highlights so the next request gets fresh data.
     */
    public function invalidateRedisCache(string $articleCoordinate): void
    {
        $redisKey = self::REDIS_PREFIX . md5($articleCoordinate);
        try {
            $this->redis->del($redisKey);
            $this->redis->del($redisKey . ':meta');
        } catch (\RedisException) {
            // Best-effort
        }
    }

    /**
     * Get cached highlights from database (deduplicated).
     */
    private function getCachedHighlights(string $articleCoordinate): array
    {
        $highlights = $this->highlightRepository->findByArticleCoordinateDeduplicated($articleCoordinate);

        $this->logger->debug('Loaded highlights from DB', [
            'coordinate' => $articleCoordinate,
            'count' => count($highlights),
        ]);

        return array_map(function (Highlight $highlight) {
            return [
                'content' => $highlight->getContent(),
                'created_at' => $highlight->getCreatedAt(),
                'pubkey' => $highlight->getPubkey(),
                'context' => $highlight->getContext(),
            ];
        }, $highlights);
    }

    /**
     * Full refresh - fetch all highlights from relays and update cache
     */
    private function fullRefresh(string $articleCoordinate): array
    {
        try {
            // Fetch from Nostr
            $highlightEvents = $this->nostrClient->getHighlightsForArticle($articleCoordinate);

            // Clear old cache
            $cutoff = new \DateTimeImmutable('-30 days');
            $this->highlightRepository->deleteOldHighlights($articleCoordinate, $cutoff);

            // Save new highlights
            $this->saveHighlights($articleCoordinate, $highlightEvents);

            // Return the fresh data
            return $this->getCachedHighlights($articleCoordinate);

        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh highlights', [
                'coordinate' => $articleCoordinate,
                'error' => $e->getMessage()
            ]);

            // Return cached data even if stale
            return $this->getCachedHighlights($articleCoordinate);
        }
    }


    /**
     * Save highlight events to database
     */
    private function saveHighlights(string $articleCoordinate, array $highlightEvents): void
    {
        $this->logger->debug('Saving highlights to database', [
            'coordinate' => $articleCoordinate,
            'event_count' => count($highlightEvents)
        ]);

        $saved = 0;
        $updated = 0;

        foreach ($highlightEvents as $event) {
            // Check if this event already exists
            $existing = $this->highlightRepository->findOneBy(['eventId' => $event->id]);
            if ($existing) {
                // Update cached_at timestamp
                $existing->setCachedAt(new \DateTimeImmutable());
                $updated++;
                continue;
            }

            // Extract the canonical coordinate from the event's own 'a' tag
            // This ensures consistency with FetchHighlightsCommand which also reads from the event
            $eventCoordinate = $articleCoordinate;
            foreach ($event->tags ?? [] as $tag) {
                if (is_array($tag) && count($tag) >= 2 && in_array($tag[0], ['a', 'A'])) {
                    if (str_starts_with($tag[1] ?? '', '30023:') || str_starts_with($tag[1] ?? '', '30024:')) {
                        $eventCoordinate = $tag[1];
                        break;
                    }
                }
            }

            // Create new highlight entity
            $highlight = new Highlight();
            $highlight->setEventId($event->id);
            $highlight->setArticleCoordinate($eventCoordinate);
            $highlight->setContent($event->content ?? '');
            $highlight->setPubkey($event->pubkey ?? '');
            $highlight->setCreatedAt($event->created_at ?? time());

            // Extract context from tags if available
            foreach ($event->tags ?? [] as $tag) {
                if (is_array($tag) && count($tag) >= 2 && $tag[0] === 'context') {
                    $highlight->setContext($tag[1]);
                    break;
                }
            }

            // Store raw event for potential future use
            $highlight->setRawEvent((array) $event);

            if (!empty($highlight->getContent())) {
                $this->entityManager->persist($highlight);
                $saved++;

                $this->logger->debug('Persisting highlight', [
                    'event_id' => $event->id,
                    'coordinate' => $articleCoordinate,
                    'content_length' => strlen($highlight->getContent())
                ]);
            }
        }

        $this->entityManager->flush();

        // Invalidate Redis so the next web request picks up fresh data
        $this->invalidateRedisCache($articleCoordinate);

        $this->logger->debug('Highlights saved to database', [
            'coordinate' => $articleCoordinate,
            'new_saved' => $saved,
            'updated' => $updated
        ]);
    }

    /**
     * Force refresh highlights for a specific article (for admin use)
     */
    public function forceRefresh(string $articleCoordinate): array
    {
        $result = $this->fullRefresh($articleCoordinate);
        $this->invalidateRedisCache($articleCoordinate);
        return $result;
    }

    /**
     * Save events directly to database (used by HighlightsController)
     * This is public so we can save highlights as we discover them
     */
    public function saveEventsToDatabase(string $articleCoordinate, array $events): void
    {
        $this->saveHighlights($articleCoordinate, $events);
    }

    /**
     * Debug method: Get all coordinates in database
     */
    public function getAllStoredCoordinates(): array
    {
        return $this->highlightRepository->getAllArticleCoordinates();
    }

    /**
     * Debug method: Get raw highlights from database
     */
    public function getDebugInfo(string $articleCoordinate): array
    {
        $highlights = $this->highlightRepository->findByArticleCoordinate($articleCoordinate);
        $lastCache = $this->highlightRepository->getLastCacheTime($articleCoordinate);
        $needsRefresh = $this->highlightRepository->needsRefresh($articleCoordinate);

        return [
            'coordinate' => $articleCoordinate,
            'count' => count($highlights),
            'last_cache' => $lastCache?->format('Y-m-d H:i:s'),
            'needs_refresh' => $needsRefresh,
            'highlights' => array_map(fn($h) => [
                'event_id' => $h->getEventId(),
                'content' => substr($h->getContent(), 0, 100),
                'pubkey' => substr($h->getPubkey(), 0, 16),
                'created_at' => $h->getCreatedAt(),
                'cached_at' => $h->getCachedAt()->format('Y-m-d H:i:s'),
            ], $highlights)
        ];
    }
}
