<?php

namespace App\UnfoldBundle\Config;

use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Loads SiteConfig by:
 * 1. Fetching AppData event (kind 30078) via naddr
 * 2. Extracting magazineNaddr and theme from AppData
 * 3. Fetching root magazine event (kind 30040) via magazineNaddr
 * 4. Building SiteConfig from magazine event + theme
 *
 * Or directly from a magazine naddr (kind 30040) using loadFromMagazine()
 */
class SiteConfigLoader
{
    private const CACHE_TTL = 120; // 2 minutes

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly CacheItemPoolInterface $unfoldCache,
        private readonly LoggerInterface $logger,
        private readonly EventRepository $eventRepository,
    ) {}

    /**
     * Load SiteConfig by AppData naddr string
     *
     * @param string $appDataNaddr naddr of the NIP-78 AppData event (kind 30078)
     * @throws \InvalidArgumentException if naddr is invalid
     * @throws \RuntimeException if events cannot be fetched
     */
    public function load(string $appDataNaddr): SiteConfig
    {
        // Check cache first
        $cacheKey = 'site_config_' . md5($appDataNaddr);
        $cacheItem = $this->unfoldCache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logger->debug('SiteConfig cache hit', ['appDataNaddr' => $appDataNaddr]);
            return $cacheItem->get();
        }

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

        // Cache it
        $cacheItem->set($siteConfig);
        $cacheItem->expiresAfter(self::CACHE_TTL);
        $this->unfoldCache->save($cacheItem);

        $this->logger->info('Loaded and cached SiteConfig', [
            'appDataNaddr' => $appDataNaddr,
            'magazineNaddr' => $appData->magazineNaddr,
            'theme' => $appData->theme,
            'title' => $siteConfig->title,
            'categories' => count($siteConfig->categories),
        ]);

        return $siteConfig;
    }

    /**
     * Load SiteConfig directly from a magazine naddr (kind 30040)
     * This bypasses the AppData layer and uses a default theme
     *
     * @param string $magazineNaddr naddr of the magazine event (kind 30040)
     * @param string $theme Theme name to use (defaults to 'default')
     * @throws \InvalidArgumentException if naddr is invalid or not kind 30040
     * @throws \RuntimeException if event cannot be fetched
     */
    public function loadFromMagazine(string $magazineNaddr, string $theme = 'default'): SiteConfig
    {
        // Check cache first
        $cacheKey = 'site_config_magazine_' . md5($magazineNaddr);
        $cacheItem = $this->unfoldCache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logger->debug('SiteConfig (magazine) cache hit', ['magazineNaddr' => $magazineNaddr]);
            return $cacheItem->get();
        }

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

        // Cache it
        $cacheItem->set($siteConfig);
        $cacheItem->expiresAfter(self::CACHE_TTL);
        $this->unfoldCache->save($cacheItem);

        $this->logger->info('Loaded and cached SiteConfig from magazine', [
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
        $this->unfoldCache->deleteItem($cacheKey);
        $this->logger->info('Invalidated SiteConfig cache', ['appDataNaddr' => $appDataNaddr]);
    }

    /**
     * Invalidate cached SiteConfig for a given magazine naddr
     */
    public function invalidateFromMagazine(string $magazineNaddr): void
    {
        $cacheKey = 'site_config_magazine_' . md5($magazineNaddr);
        $this->unfoldCache->deleteItem($cacheKey);
        $this->logger->info('Invalidated SiteConfig cache (magazine)', ['magazineNaddr' => $magazineNaddr]);
    }
}

