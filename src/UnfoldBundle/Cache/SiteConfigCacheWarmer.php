<?php

namespace App\UnfoldBundle\Cache;

use App\Entity\UnfoldSite;
use App\Service\GenericEventProjector;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrClient;
use App\UnfoldBundle\Config\SiteConfigLoader;
use App\UnfoldBundle\Content\ContentProvider;
use Psr\Log\LoggerInterface;

/**
 * Warms the SiteConfig and content cache for UnfoldSite entities
 */
class SiteConfigCacheWarmer
{
    public function __construct(
        private readonly SiteConfigLoader $siteConfigLoader,
        private readonly ContentProvider $contentProvider,
        private readonly LoggerInterface $logger,
        private readonly NostrClient $nostrClient,
        private readonly GenericEventProjector $eventProjector,
        private readonly EventIngestionListener $eventIngestionListener,
    ) {}

    /**
     * Warm cache for a single UnfoldSite (SiteConfig + content)
     */
    public function warmSite(UnfoldSite $site): bool
    {
        try {
            $this->logger->info('Warming cache for UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'coordinate' => $site->getCoordinate(),
            ]);

            // 0. Refresh magazine tree from network so the local DB + graph tables
            //    have the latest events before we build the cache.
            $this->logger->info('Refreshing magazine tree from network...');
            $this->refreshMagazineTreeFromNetwork($site->getCoordinate());

            // 1. Invalidate the SiteConfig cache first so loadFromCoordinate fetches fresh data.
            //    Without this, a fresh/stale SWR entry would be returned as-is (the background
            //    register_shutdown_function refresh never fires in console commands).
            $this->logger->info('Invalidating existing SiteConfig cache...');
            $this->siteConfigLoader->invalidateFromCoordinate($site->getCoordinate());

            // 2. Load and cache the SiteConfig (forced fresh fetch because we just invalidated)
            $this->logger->info('Loading SiteConfig from coordinate...');
            $siteConfig = $this->siteConfigLoader->loadFromCoordinate($site->getCoordinate());

            // Check if we got a placeholder
            if ($siteConfig->title === 'Loading...') {
                $this->logger->warning('Got placeholder SiteConfig - fetch may have failed', [
                    'subdomain' => $site->getSubdomain(),
                    'coordinate' => $site->getCoordinate(),
                ]);
                return false;
            }

            $this->logger->info('SiteConfig loaded', [
                'title' => $siteConfig->title,
                'categories_count' => count($siteConfig->categories),
            ]);

            // 3. Invalidate all content caches so category/post fetches are forced fresh
            $this->logger->info('Invalidating existing content caches...');
            $this->contentProvider->invalidateSiteCache($siteConfig);

            // 4. Warm categories cache (forced fresh fetch)
            $this->logger->info('Loading categories...');
            $categories = $this->contentProvider->getCategories($siteConfig);

            if (empty($categories)) {
                $this->logger->warning('No categories found - may be placeholder data');
            }

            // 5. Warm category posts cache for each category
            foreach ($categories as $category) {
                $this->logger->info('Loading posts for category', ['category' => $category->slug]);
                $this->contentProvider->getCategoryPosts($category->coordinate);
            }

            // 6. Warm home posts cache
            $this->logger->info('Loading home posts...');
            $this->contentProvider->getHomePosts($siteConfig, 3);

            $this->logger->info('Cache warmed successfully', [
                'subdomain' => $site->getSubdomain(),
                'title' => $siteConfig->title,
                'categories' => count($categories),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to warm cache for UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'coordinate' => $site->getCoordinate(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Refresh the magazine event tree from the network.
     *
     * Fetches the root magazine event and all category events from relays,
     * ingesting any that are newer than what's in the local DB.
     * This updates the event table AND the graph tables (current_record +
     * parsed_reference), so that subsequent graph-backed lookups return
     * fresh data.
     */
    private function refreshMagazineTreeFromNetwork(string $coordinate): void
    {
        $decoded = $this->parseCoordinate($coordinate);
        if ($decoded === null) {
            $this->logger->warning('Cannot refresh: invalid coordinate', ['coordinate' => $coordinate]);
            return;
        }

        // 1. Fetch the root magazine event from relays
        try {
            $magazineEvent = $this->nostrClient->getEventByNaddr($decoded);
            if ($magazineEvent === null) {
                $this->logger->warning('Magazine event not found on relays', ['coordinate' => $coordinate]);
                return;
            }

            // Ingest the magazine event (updates DB + graph if newer)
            $entity = $this->eventProjector->projectEventFromNostrEvent($magazineEvent, 'cache-warm');

            // Ensure graph tables are up to date even if the event already existed
            // (processEvent is idempotent — it re-parses references and re-upserts current_record)
            $this->eventIngestionListener->processEvent($entity);

            $this->logger->info('Refreshed magazine event from network', [
                'coordinate' => $coordinate,
                'event_id' => substr($magazineEvent->id ?? '', 0, 16) . '...',
                'created_at' => $magazineEvent->created_at ?? 0,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to refresh magazine event from network', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        // 2. Extract category coordinates from the magazine event's 'a' tags
        $categoryCoordinates = [];
        foreach ($magazineEvent->tags ?? [] as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === 'a' && isset($tag[1])) {
                $categoryCoordinates[] = $tag[1];
            }
        }

        if (empty($categoryCoordinates)) {
            $this->logger->info('No category coordinates found in magazine event');
            return;
        }

        // 3. Fetch and ingest all category events from relays
        try {
            $categoryEvents = $this->nostrClient->getEventsByCoordinates($categoryCoordinates);

            $refreshed = 0;
            foreach ($categoryEvents as $coord => $event) {
                try {
                    $entity = $this->eventProjector->projectEventFromNostrEvent($event, 'cache-warm');
                    // Ensure graph tables are up to date (idempotent)
                    $this->eventIngestionListener->processEvent($entity);
                    $refreshed++;
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to ingest category event', [
                        'coordinate' => $coord,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Refreshed category events from network', [
                'expected' => count($categoryCoordinates),
                'fetched' => count($categoryEvents),
                'ingested' => $refreshed,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch category events from network', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse a coordinate string (kind:pubkey:identifier) into naddr-like array
     */
    private function parseCoordinate(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'kind' => (int) $parts[0],
            'pubkey' => strtolower($parts[1]),
            'identifier' => $parts[2],
            'relays' => [],
        ];
    }

    /**
     * Invalidate cache for a single UnfoldSite
     */
    public function invalidateSite(UnfoldSite $site): void
    {
        $this->siteConfigLoader->invalidateFromCoordinate($site->getCoordinate());
    }

    /**
     * Warm cache for multiple sites
     *
     * @param iterable<UnfoldSite> $sites
     * @return array{success: int, failed: int}
     */
    public function warmAll(iterable $sites): array
    {
        $success = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($this->warmSite($site)) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }
}
