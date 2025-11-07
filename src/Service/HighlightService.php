<?php

namespace App\Service;

use App\Entity\Highlight;
use App\Repository\HighlightRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class HighlightService
{
    private const CACHE_DURATION_HOURS = 24; // Refresh every 24 hours
    private const TOP_OFF_DURATION_HOURS = 1; // Top off every hour

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HighlightRepository $highlightRepository,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Get highlights for an article, using cache when possible
     */
    public function getHighlightsForArticle(string $articleCoordinate): array
    {
        $this->logger->info('Getting highlights for article', [
            'coordinate' => $articleCoordinate,
            'coordinate_length' => strlen($articleCoordinate)
        ]);

        // Check if we need a full refresh (cache is stale)
        $needsFullRefresh = $this->highlightRepository->needsRefresh($articleCoordinate, self::CACHE_DURATION_HOURS);

        $this->logger->info('Cache refresh check', [
            'coordinate' => $articleCoordinate,
            'needs_refresh' => $needsFullRefresh
        ]);

        if ($needsFullRefresh) {
            $this->logger->info('Full refresh needed for highlights', ['coordinate' => $articleCoordinate]);
            return $this->fullRefresh($articleCoordinate);
        }

        // Check if we should top off with recent highlights
        $lastCacheTime = $this->highlightRepository->getLastCacheTime($articleCoordinate);
        $shouldTopOff = false;

        if ($lastCacheTime) {
            $hoursSinceLastCache = (new \DateTimeImmutable())->getTimestamp() - $lastCacheTime->getTimestamp();
            $shouldTopOff = ($hoursSinceLastCache / 3600) >= self::TOP_OFF_DURATION_HOURS;
        }

        if ($shouldTopOff) {
            $this->logger->info('Topping off highlights with recent data', ['coordinate' => $articleCoordinate]);
            $this->topOffHighlights($articleCoordinate);
        }

        // Return cached highlights
        $cached = $this->getCachedHighlights($articleCoordinate);
        $this->logger->info('Returning cached highlights', [
            'coordinate' => $articleCoordinate,
            'count' => count($cached)
        ]);
        return $cached;
    }

    /**
     * Get cached highlights from database
     */
    private function getCachedHighlights(string $articleCoordinate): array
    {
        $highlights = $this->highlightRepository->findByArticleCoordinate($articleCoordinate);

        $this->logger->info('Found highlights in database', [
            'coordinate' => $articleCoordinate,
            'count' => count($highlights)
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
     * Top off - fetch only recent highlights to supplement cache
     */
    private function topOffHighlights(string $articleCoordinate): void
    {
        try {
            $lastCacheTime = $this->highlightRepository->getLastCacheTime($articleCoordinate);

            // Fetch recent highlights (since last cache time)
            $highlightEvents = $this->nostrClient->getHighlightsForArticle($articleCoordinate, 50);

            // Filter to only new events (created after last cache)
            if ($lastCacheTime) {
                $cutoffTimestamp = $lastCacheTime->getTimestamp();
                $highlightEvents = array_filter($highlightEvents, function ($event) use ($cutoffTimestamp) {
                    return ($event->created_at ?? 0) > $cutoffTimestamp;
                });
            }

            if (!empty($highlightEvents)) {
                $this->logger->info('Adding new highlights to cache', [
                    'coordinate' => $articleCoordinate,
                    'count' => count($highlightEvents)
                ]);

                $this->saveHighlights($articleCoordinate, $highlightEvents);
            }

        } catch (\Exception $e) {
            $this->logger->warning('Failed to top off highlights', [
                'coordinate' => $articleCoordinate,
                'error' => $e->getMessage()
            ]);
            // Non-critical, just log and continue
        }
    }

    /**
     * Save highlight events to database
     */
    private function saveHighlights(string $articleCoordinate, array $highlightEvents): void
    {
        $this->logger->info('Saving highlights to database', [
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

            // Create new highlight entity
            $highlight = new Highlight();
            $highlight->setEventId($event->id);
            $highlight->setArticleCoordinate($articleCoordinate);
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

        $this->logger->info('Highlights saved to database', [
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
        return $this->fullRefresh($articleCoordinate);
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
