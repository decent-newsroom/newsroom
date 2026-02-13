<?php

namespace App\UnfoldBundle\Config;

use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Log\LoggerInterface;

/**
 * Loads SiteConfig by:
 * 1. Fetching AppData event (kind 30078) via naddr
 * 2. Extracting magazineNaddr and theme from AppData
 * 3. Fetching root magazine event (kind 30040) via magazineNaddr
 * 4. Building SiteConfig from magazine event + theme
 *
 * Or directly from a magazine naddr (kind 30040) using loadFromMagazine()
 *
 * Uses stale-while-revalidate caching to prevent timeouts:
 * - Fresh cache (< 2 min): serve immediately
 * - Stale cache (< 1 hour): serve immediately, refresh in background
 * - Expired cache (> 1 hour): try refresh with fallback to stale
 */
class SiteConfigLoader
{
    private const FRESH_TTL = 120;   // 2 minutes - serve without revalidation
    private const STALE_TTL = 3600;  // 1 hour - serve stale while revalidating

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly StaleWhileRevalidateCache $swrCache,
        private readonly LoggerInterface $logger,
        private readonly EventRepository $eventRepository,
    ) {}

    /**
     * Load SiteConfig by AppData naddr string
     *
     * @param string $appDataNaddr naddr of the NIP-78 AppData event (kind 30078)
     * @throws \InvalidArgumentException if naddr is invalid
     */
    public function load(string $appDataNaddr): SiteConfig
    {
        $cacheKey = 'site_config_' . md5($appDataNaddr);

        // Create a placeholder SiteConfig to use if fetch fails
        $placeholder = $this->createPlaceholderConfig($appDataNaddr, 'default');

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchSiteConfigFromAppData($appDataNaddr),
            self::FRESH_TTL,
            self::STALE_TTL,
            $placeholder // Return placeholder on failure
        );
    }

    /**
     * Fetch SiteConfig from AppData (internal fetcher for cache)
     */
    private function fetchSiteConfigFromAppData(string $appDataNaddr): SiteConfig
    {
        // 1. Load AppData event (kind 30078)
        $appData = $this->loadAppData($appDataNaddr);

        // 2. Load magazine event using magazineNaddr from AppData
        $magazineDecoded = $this->decodeNaddr($appData->magazineNaddr);
        $magazineEvent = $this->nostrClient->getEventByNaddr($magazineDecoded);

        if ($magazineEvent === null) {
            throw new \RuntimeException(sprintf(
                'Could not fetch magazine event for naddr: %s',
                $appData->magazineNaddr
            ));
        }

        // 3. Build SiteConfig from magazine event + theme from AppData
        $siteConfig = SiteConfig::fromEvent($magazineEvent, $appData->magazineNaddr, $appData->theme);

        $this->logger->info('Loaded SiteConfig from AppData', [
            'appDataNaddr' => $appDataNaddr,
            'magazineNaddr' => $appData->magazineNaddr,
            'theme' => $appData->theme,
            'title' => $siteConfig->title,
            'categories' => count($siteConfig->categories),
        ]);

        return $siteConfig;
    }

    /**
     * Load SiteConfig directly from a magazine coordinate (kind:pubkey:identifier)
     * This is the preferred method - simpler than naddr
     *
     * @param string $coordinate Magazine coordinate (format: 30040:pubkey:slug)
     * @param string $theme Theme name to use (defaults to 'default')
     * @throws \InvalidArgumentException if coordinate is invalid or not kind 30040
     */
    public function loadFromCoordinate(string $coordinate, string $theme = 'default'): SiteConfig
    {
        $cacheKey = 'site_config_coord_' . md5($coordinate);

        // Create a placeholder SiteConfig to use if fetch fails
        $placeholder = $this->createPlaceholderConfig($coordinate, $theme);

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchSiteConfigFromCoordinate($coordinate, $theme),
            self::FRESH_TTL,
            self::STALE_TTL,
            $placeholder // Return placeholder on failure
        );
    }

    /**
     * Fetch SiteConfig from coordinate (internal fetcher for cache)
     */
    private function fetchSiteConfigFromCoordinate(string $coordinate, string $theme): SiteConfig
    {
        $this->logger->info('Fetching SiteConfig from coordinate', [
            'coordinate' => $coordinate,
            'theme' => $theme,
        ]);

        try {
            // Parse coordinate: kind:pubkey:identifier
            $decoded = $this->parseCoordinate($coordinate);

            // Verify it's a kind 30040 event
            if ($decoded['kind'] !== KindsEnum::PUBLICATION_INDEX->value) {
                throw new \InvalidArgumentException(sprintf(
                    'Expected magazine event (kind %d), got kind %d',
                    KindsEnum::PUBLICATION_INDEX->value,
                    $decoded['kind']
                ));
            }

            // Try database first (fast path)
            $this->logger->info('Checking database for magazine event', [
                'kind' => $decoded['kind'],
                'pubkey' => substr($decoded['pubkey'], 0, 8) . '...',
                'identifier' => $decoded['identifier'],
            ]);

            $magazineEvent = $this->loadEventFromDatabase($decoded);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to parse coordinate or check database', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        // Fallback to network if not in database
        if ($magazineEvent === null) {
            $this->logger->info('Magazine not in database, fetching from network', [
                'coordinate' => $coordinate,
                'pubkey' => substr($decoded['pubkey'], 0, 8) . '...',
                'identifier' => $decoded['identifier'],
            ]);

            $magazineEvent = $this->nostrClient->getEventByNaddr($decoded);

            if ($magazineEvent === null) {
                $this->logger->error('Failed to fetch magazine event from network', [
                    'coordinate' => $coordinate,
                    'pubkey' => substr($decoded['pubkey'], 0, 8) . '...',
                    'identifier' => $decoded['identifier'],
                ]);
            }
        } else {
            $this->logger->debug('Magazine event found in database');
        }

        if ($magazineEvent === null) {
            throw new \RuntimeException(sprintf(
                'Could not fetch magazine event for coordinate: %s (tried database and network)',
                $coordinate
            ));
        }

        // Build SiteConfig directly from magazine event
        $siteConfig = SiteConfig::fromEvent($magazineEvent, $coordinate, $theme);

        $this->logger->info('Loaded SiteConfig from coordinate', [
            'coordinate' => $coordinate,
            'theme' => $theme,
            'title' => $siteConfig->title,
            'categories' => count($siteConfig->categories),
        ]);

        return $siteConfig;
    }

    /**
     * Parse a coordinate string (kind:pubkey:identifier) into array
     * @throws \InvalidArgumentException if coordinate is invalid
     */
    private function parseCoordinate(string $coordinate): array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid coordinate format: %s (expected kind:pubkey:identifier)',
                $coordinate
            ));
        }

        return [
            'kind' => (int) $parts[0],
            'pubkey' => $parts[1],
            'identifier' => $parts[2],
            'relays' => [],
        ];
    }

    /**
     * @deprecated Use loadFromCoordinate() instead
     * Load SiteConfig directly from a magazine naddr (kind 30040)
     * This bypasses the AppData layer and uses a default theme
     *
     * @param string $magazineNaddr naddr of the magazine event (kind 30040)
     * @param string $theme Theme name to use (defaults to 'default')
     * @throws \InvalidArgumentException if naddr is invalid or not kind 30040
     */
    public function loadFromMagazine(string $magazineNaddr, string $theme = 'default'): SiteConfig
    {
        // Check if it's a coordinate (contains colons but doesn't start with naddr)
        if (str_contains($magazineNaddr, ':') && !str_starts_with($magazineNaddr, 'naddr')) {
            return $this->loadFromCoordinate($magazineNaddr, $theme);
        }

        $cacheKey = 'site_config_magazine_' . md5($magazineNaddr);

        // Create a placeholder SiteConfig to use if fetch fails
        $placeholder = $this->createPlaceholderConfig($magazineNaddr, $theme);

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchSiteConfigFromMagazine($magazineNaddr, $theme),
            self::FRESH_TTL,
            self::STALE_TTL,
            $placeholder // Return placeholder on failure
        );
    }

    /**
     * Create a placeholder SiteConfig for when the real one can't be loaded
     */
    private function createPlaceholderConfig(string $naddr, string $theme): SiteConfig
    {
        return new SiteConfig(
            naddr: $naddr,
            title: 'Loading...',
            description: 'Content is being loaded. Please refresh the page.',
            logo: null,
            categories: [],
            pubkey: '',
            theme: $theme,
        );
    }

    /**
     * Fetch SiteConfig directly from magazine (internal fetcher for cache)
     */
    private function fetchSiteConfigFromMagazine(string $magazineNaddr, string $theme): SiteConfig
    {
        // Decode and validate
        $decoded = $this->decodeNaddr($magazineNaddr);

        // Verify it's a kind 30040 event
        if ($decoded['kind'] !== KindsEnum::PUBLICATION_INDEX->value) {
            throw new \InvalidArgumentException(sprintf(
                'Expected magazine event (kind %d), got kind %d',
                KindsEnum::PUBLICATION_INDEX->value,
                $decoded['kind']
            ));
        }

        // Try database first (fast path)
        $magazineEvent = $this->loadEventFromDatabase($decoded);

        // Fallback to network if not in database
        if ($magazineEvent === null) {
            $this->logger->debug('Magazine not in database, fetching from network', [
                'naddr' => $magazineNaddr
            ]);
            $magazineEvent = $this->nostrClient->getEventByNaddr($decoded);
        }

        if ($magazineEvent === null) {
            throw new \RuntimeException(sprintf(
                'Could not fetch magazine event for naddr: %s',
                $magazineNaddr
            ));
        }

        // Build SiteConfig directly from magazine event
        $siteConfig = SiteConfig::fromEvent($magazineEvent, $magazineNaddr, $theme);

        $this->logger->info('Loaded SiteConfig from magazine', [
            'magazineNaddr' => $magazineNaddr,
            'theme' => $theme,
            'title' => $siteConfig->title,
            'categories' => count($siteConfig->categories),
        ]);

        return $siteConfig;
    }

    /**
     * Try to load event from database first (fast path)
     */
    private function loadEventFromDatabase(array $decoded): ?object
    {
        $event = $this->eventRepository->findByNaddr(
            $decoded['kind'],
            $decoded['pubkey'],
            $decoded['identifier']
        );

        if ($event === null) {
            return null;
        }

        // Convert Entity to stdClass object matching NostrClient format
        return (object) [
            'id' => $event->getId(),
            'pubkey' => $event->getPubkey(),
            'kind' => $event->getKind(),
            'content' => $event->getContent(),
            'tags' => $event->getTags(),
            'created_at' => $event->getCreatedAt(),
            'sig' => $event->getSig(),
        ];
    }

    /**
     * Load AppData from NIP-78 event
     */
    private function loadAppData(string $naddr): AppData
    {
        $decoded = $this->decodeNaddr($naddr);

        // Verify it's a kind 30078 event
        if ($decoded['kind'] !== KindsEnum::APP_DATA->value) {
            throw new \InvalidArgumentException(sprintf(
                'Expected AppData event (kind %d), got kind %d',
                KindsEnum::APP_DATA->value,
                $decoded['kind']
            ));
        }

        $event = $this->nostrClient->getEventByNaddr($decoded);

        if ($event === null) {
            throw new \RuntimeException(sprintf('Could not fetch AppData event for naddr: %s', $naddr));
        }

        return AppData::fromEvent($event, $naddr);
    }

    /**
     * Decode naddr string to array with kind, pubkey, identifier, relays
     *
     * @throws \InvalidArgumentException if naddr is invalid
     */
    private function decodeNaddr(string $naddr): array
    {
        try {
            $decoded = new Bech32($naddr);

            if ($decoded->type !== 'naddr') {
                throw new \InvalidArgumentException(sprintf('Expected naddr, got %s', $decoded->type));
            }

            /** @var NAddr $data */
            $data = $decoded->data;

            return [
                'kind' => $data->kind,
                'pubkey' => $data->pubkey,
                'identifier' => $data->identifier,
                'relays' => $data->relays ?? [],
            ];
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('Invalid naddr: %s (%s)', $naddr, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Invalidate cached SiteConfig for a given AppData naddr
     */
    public function invalidate(string $appDataNaddr): void
    {
        $cacheKey = 'site_config_' . md5($appDataNaddr);
        $this->swrCache->invalidate($cacheKey);
        $this->logger->info('Invalidated SiteConfig cache', ['appDataNaddr' => $appDataNaddr]);
    }

    /**
     * Invalidate cached SiteConfig for a given magazine naddr
     */
    public function invalidateFromMagazine(string $magazineNaddr): void
    {
        $cacheKey = 'site_config_magazine_' . md5($magazineNaddr);
        $this->swrCache->invalidate($cacheKey);
        $this->logger->info('Invalidated SiteConfig cache (magazine)', ['magazineNaddr' => $magazineNaddr]);
    }

    /**
     * Invalidate cached SiteConfig for a given coordinate
     */
    public function invalidateFromCoordinate(string $coordinate): void
    {
        $cacheKey = 'site_config_coord_' . md5($coordinate);
        $this->swrCache->invalidate($cacheKey);
        $this->logger->info('Invalidated SiteConfig cache (coordinate)', ['coordinate' => $coordinate]);
    }
}

