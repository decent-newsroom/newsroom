<?php

namespace App\UnfoldBundle\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Stale-While-Revalidate cache wrapper for Unfold
 *
 * This cache strategy ensures pages always load quickly:
 * 1. If cache is fresh (< TTL), return cached value
 * 2. If cache is stale (> TTL but < stale TTL), return stale value AND trigger background refresh
 * 3. If cache is expired (> stale TTL), fetch fresh value synchronously
 *
 * This prevents timeout issues by always serving cached content while refreshing in the background.
 */
class StaleWhileRevalidateCache
{
    /**
     * Metadata suffix for storing cache timestamps
     */
    private const META_SUFFIX = '_meta';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get a cached value with stale-while-revalidate semantics
     *
     * @param string $key Cache key
     * @param callable $fetcher Function to fetch fresh value: fn() => mixed
     * @param int $freshTtl TTL in seconds for "fresh" cache (serve without revalidation)
     * @param int $staleTtl TTL in seconds for "stale" cache (serve but trigger revalidation)
     * @param mixed $default Default value to return if fetch fails and no cache exists
     * @return mixed The cached or fresh value
     */
    public function get(string $key, callable $fetcher, int $freshTtl = 120, int $staleTtl = 3600, mixed $default = null): mixed
    {
        $cacheItem = $this->cache->getItem($key);
        $metaItem = $this->cache->getItem($key . self::META_SUFFIX);

        // Check if we have a cached value
        if ($cacheItem->isHit()) {
            $cachedValue = $cacheItem->get();
            $meta = $metaItem->isHit() ? $metaItem->get() : null;
            $cachedAt = $meta['cached_at'] ?? 0;
            $isPlaceholder = $meta['is_placeholder'] ?? false;
            $now = time();
            $age = $now - $cachedAt;

            // If it's a placeholder, treat it as expired immediately (try to refresh)
            if ($isPlaceholder) {
                $this->logger->debug('SWR cache hit but value is placeholder, attempting refresh', [
                    'key' => $key,
                    'age' => $age,
                ]);

                try {
                    return $this->fetchAndCache($key, $fetcher, $freshTtl, $staleTtl);
                } catch (\Throwable $e) {
                    $this->logger->warning('SWR refresh failed, serving placeholder', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                    return $cachedValue;
                }
            }

            // Fresh: serve immediately
            if ($age < $freshTtl) {
                $this->logger->debug('SWR cache hit (fresh)', [
                    'key' => $key,
                    'age' => $age,
                    'freshTtl' => $freshTtl,
                ]);
                return $cachedValue;
            }

            // Stale: serve immediately but trigger background revalidation
            if ($age < $staleTtl) {
                $this->logger->debug('SWR cache hit (stale, revalidating)', [
                    'key' => $key,
                    'age' => $age,
                    'staleTtl' => $staleTtl,
                ]);

                // Trigger background refresh (fire and forget)
                $this->triggerBackgroundRefresh($key, $fetcher, $freshTtl, $staleTtl);

                return $cachedValue;
            }

            // Expired but we still have a value - try to refresh, fall back to stale
            $this->logger->debug('SWR cache expired, attempting refresh with fallback', [
                'key' => $key,
                'age' => $age,
            ]);

            try {
                return $this->fetchAndCache($key, $fetcher, $freshTtl, $staleTtl);
            } catch (\Throwable $e) {
                $this->logger->warning('SWR refresh failed, serving expired cache', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
                return $cachedValue;
            }
        }

        // No cache at all - try to fetch, return default on failure
        $this->logger->debug('SWR cache miss, fetching', ['key' => $key]);

        try {
            return $this->fetchAndCache($key, $fetcher, $freshTtl, $staleTtl);
        } catch (\Throwable $e) {
            $this->logger->error('SWR fetch failed on cache miss, returning default', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            // If we have a default value, cache it briefly to avoid hammering
            // Mark it as placeholder so it's retried aggressively
            if ($default !== null) {
                $cacheItem = $this->cache->getItem($key);
                $cacheItem->set($default);
                $cacheItem->expiresAfter(30); // Cache default for 30 seconds
                $this->cache->save($cacheItem);

                // Save metadata marking this as placeholder
                $metaItem = $this->cache->getItem($key . self::META_SUFFIX);
                $metaItem->set([
                    'cached_at' => time(),
                    'is_placeholder' => true,
                ]);
                $metaItem->expiresAfter(30);
                $this->cache->save($metaItem);
            }

            return $default;
        }
    }

    /**
     * Fetch fresh value and cache it
     */
    private function fetchAndCache(string $key, callable $fetcher, int $freshTtl, int $staleTtl): mixed
    {
        $value = $fetcher();

        $cacheItem = $this->cache->getItem($key);
        $cacheItem->set($value);
        $cacheItem->expiresAfter($staleTtl); // Store for full stale TTL
        $this->cache->save($cacheItem);

        // Store metadata with timestamp and mark as NOT placeholder
        $metaItem = $this->cache->getItem($key . self::META_SUFFIX);
        $metaItem->set([
            'cached_at' => time(),
            'is_placeholder' => false,
        ]);
        $metaItem->expiresAfter($staleTtl);
        $this->cache->save($metaItem);

        $this->logger->debug('SWR cached fresh value', [
            'key' => $key,
            'freshTtl' => $freshTtl,
            'staleTtl' => $staleTtl,
        ]);

        return $value;
    }

    /**
     * Trigger a background refresh of the cache
     *
     * For now, this does an inline refresh in a try/catch to not block.
     * In the future, this could dispatch a Messenger message for true async refresh.
     */
    private function triggerBackgroundRefresh(string $key, callable $fetcher, int $freshTtl, int $staleTtl): void
    {
        // Use register_shutdown_function for fire-and-forget refresh
        // This runs after the response is sent
        register_shutdown_function(function () use ($key, $fetcher, $freshTtl, $staleTtl) {
            try {
                $this->fetchAndCache($key, $fetcher, $freshTtl, $staleTtl);
                $this->logger->debug('SWR background refresh completed', ['key' => $key]);
            } catch (\Throwable $e) {
                $this->logger->warning('SWR background refresh failed', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Invalidate a cache entry
     */
    public function invalidate(string $key): void
    {
        $this->cache->deleteItem($key);
        $this->cache->deleteItem($key . self::META_SUFFIX);
    }

    /**
     * Warm a cache entry (fetch and store without returning)
     */
    public function warm(string $key, callable $fetcher, int $freshTtl = 120, int $staleTtl = 3600): void
    {
        try {
            $this->fetchAndCache($key, $fetcher, $freshTtl, $staleTtl);
        } catch (\Throwable $e) {
            $this->logger->warning('SWR cache warm failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}



