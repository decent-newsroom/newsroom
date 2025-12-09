<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\UserEntityRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Service to manage cached muted pubkeys for filtering articles
 */
class MutedPubkeysService
{
    private const CACHE_KEY = 'muted_pubkeys';
    private const CACHE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Get muted pubkeys from cache, or refresh if not available
     * @return string[] Array of hex pubkeys
     */
    public function getMutedPubkeys(): array
    {
        try {
            $cacheItem = $this->cache->getItem(self::CACHE_KEY);

            if ($cacheItem->isHit()) {
                return $cacheItem->get();
            }

            return $this->refreshCache();
        } catch (\Exception $e) {
            $this->logger->error('Error getting muted pubkeys from cache', [
                'error' => $e->getMessage()
            ]);

            // Fallback: get directly from database
            try {
                return $this->userRepository->getMutedPubkeys();
            } catch (\Exception $e) {
                $this->logger->error('Error getting muted pubkeys from database', [
                    'error' => $e->getMessage()
                ]);
                return [];
            }
        }
    }

    /**
     * Refresh the muted pubkeys cache
     * Call this when a user is muted or unmuted
     * @return string[] The refreshed array of hex pubkeys
     */
    public function refreshCache(): array
    {
        try {
            $pubkeys = $this->userRepository->getMutedPubkeys();

            $cacheItem = $this->cache->getItem(self::CACHE_KEY);
            $cacheItem->set($pubkeys);
            $cacheItem->expiresAfter(self::CACHE_TTL);
            $this->cache->save($cacheItem);

            $this->logger->info('Muted pubkeys cache refreshed', [
                'count' => count($pubkeys)
            ]);

            return $pubkeys;
        } catch (\Exception $e) {
            $this->logger->error('Error refreshing muted pubkeys cache', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Invalidate the cache (force refresh on next access)
     */
    public function invalidateCache(): void
    {
        try {
            $this->cache->deleteItem(self::CACHE_KEY);
            $this->logger->info('Muted pubkeys cache invalidated');
        } catch (\Exception $e) {
            $this->logger->error('Error invalidating muted pubkeys cache', [
                'error' => $e->getMessage()
            ]);
        }
    }
}

