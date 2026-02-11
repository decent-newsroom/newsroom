<?php

namespace App\Service\Cache;

use App\ReadModel\RedisView\RedisBaseObject;
use App\ReadModel\RedisView\RedisReadingListView;
use App\ReadModel\RedisView\RedisViewFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for storing and fetching Redis view objects
 * Enables single-GET page rendering by caching complete view objects
 */
class RedisViewStore
{
    private const KEY_LATEST_ARTICLES = 'view:articles:latest';
    private const KEY_LATEST_HIGHLIGHTS = 'view:highlights:latest';
    private const KEY_USER_ARTICLES = 'view:user:articles:%s'; // sprintf with pubkey
    private const KEY_PROFILE_TAB = 'view:profile:tab:%s:%s'; // sprintf with pubkey and tab

    private const DEFAULT_TTL = 300; // 5 minutes

    // Stale-while-revalidate TTLs
    private const PROFILE_STALE_TTL = 60; // Consider stale after 1 minute
    private const PROFILE_MAX_TTL = 86400; // Hard expiry after 1 day

    public function __construct(
        private readonly \Redis $redis,
        private readonly RedisViewFactory $factory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Store latest articles view
     * @param array<RedisBaseObject> $baseObjects
     */
    public function storeLatestArticles(array $baseObjects): void
    {
        $this->storeView(self::KEY_LATEST_ARTICLES, $baseObjects, self::DEFAULT_TTL);
    }

    /**
     * Fetch latest articles view
     * @return array|null Array of denormalized base objects or null if not cached
     */
    public function fetchLatestArticles(): ?array
    {
        return $this->fetchView(self::KEY_LATEST_ARTICLES);
    }

    /**
     * Store latest highlights view
     * @param array<RedisBaseObject> $baseObjects
     */
    public function storeLatestHighlights(array $baseObjects): void
    {
        $this->storeView(self::KEY_LATEST_HIGHLIGHTS, $baseObjects, self::DEFAULT_TTL);
    }

    /**
     * Fetch latest highlights view
     * @return array|null Array of denormalized base objects or null if not cached
     */
    public function fetchLatestHighlights(): ?array
    {
        return $this->fetchView(self::KEY_LATEST_HIGHLIGHTS);
    }

    /**
     * Store user articles view
     * @param string $pubkey User's pubkey
     * @param array<RedisBaseObject> $baseObjects
     */
    public function storeUserArticles(string $pubkey, array $baseObjects): void
    {
        $key = sprintf(self::KEY_USER_ARTICLES, $pubkey);
        $this->storeView($key, $baseObjects, self::DEFAULT_TTL);
    }

    /**
     * Fetch user articles view
     * @param string $pubkey User's pubkey
     * @return array|null Array of denormalized base objects or null if not cached
     */
    public function fetchUserArticles(string $pubkey): ?array
    {
        $key = sprintf(self::KEY_USER_ARTICLES, $pubkey);
        return $this->fetchView($key);
    }

    /**
     * Invalidate user articles cache
     */
    public function invalidateUserArticles(string $pubkey): void
    {
        $key = sprintf(self::KEY_USER_ARTICLES, $pubkey);
        try {
            $this->redis->del($key);
            $this->logger->debug('Invalidated user articles cache', ['pubkey' => $pubkey, 'key' => $key]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate user articles cache', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Store profile tab data with stale-while-revalidate pattern.
     * Data includes a timestamp to determine staleness.
     *
     * @param string $pubkey User's pubkey
     * @param string $tab Tab name (overview, articles, media, highlights, etc.)
     * @param array $data Tab-specific data to cache
     */
    public function storeProfileTabData(string $pubkey, string $tab, array $data): void
    {
        $key = sprintf(self::KEY_PROFILE_TAB, $pubkey, $tab);

        try {
            $cacheData = [
                'data' => $data,
                'cached_at' => time(),
            ];

            $json = json_encode($cacheData, JSON_THROW_ON_ERROR);
            $compressed = $this->shouldCompress($json) ? gzcompress($json, 6) : $json;
            $isCompressed = $compressed !== $json;

            $this->redis->setex($key, self::PROFILE_MAX_TTL, $compressed);

            if ($isCompressed) {
                $this->redis->setex($key . ':compressed', self::PROFILE_MAX_TTL, '1');
            } else {
                $this->redis->del($key . ':compressed');
            }

            $this->logger->debug('Stored profile tab data', [
                'key' => $key,
                'tab' => $tab,
                'size_bytes' => strlen($compressed),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to store profile tab data', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch profile tab data with stale-while-revalidate info.
     * Returns cached data immediately, with a flag indicating if it's stale.
     *
     * @param string $pubkey User's pubkey
     * @param string $tab Tab name
     * @return array{data: array|null, isStale: bool, isCached: bool}
     */
    public function fetchProfileTabData(string $pubkey, string $tab): array
    {
        $key = sprintf(self::KEY_PROFILE_TAB, $pubkey, $tab);

        try {
            $compressed = $this->redis->get($key);

            if ($compressed === false || $compressed === null) {
                return ['data' => null, 'isStale' => true, 'isCached' => false];
            }

            // Check if compressed
            $isCompressed = $this->redis->get($key . ':compressed') !== false;

            if ($isCompressed) {
                $json = gzuncompress($compressed);
                if ($json === false) {
                    return ['data' => null, 'isStale' => true, 'isCached' => false];
                }
            } else {
                $json = $compressed;
            }

            $cacheData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $cachedAt = $cacheData['cached_at'] ?? 0;
            $age = time() - $cachedAt;
            $isStale = $age > self::PROFILE_STALE_TTL;

            $this->logger->debug('Fetched profile tab data', [
                'key' => $key,
                'age_seconds' => $age,
                'isStale' => $isStale,
            ]);

            return [
                'data' => $cacheData['data'] ?? null,
                'isStale' => $isStale,
                'isCached' => true,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch profile tab data', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return ['data' => null, 'isStale' => true, 'isCached' => false];
        }
    }

    /**
     * Invalidate all profile tab caches for a user
     */
    public function invalidateProfileTabs(string $pubkey): void
    {
        $tabs = ['overview', 'articles', 'media', 'highlights', 'drafts', 'bookmarks', 'stats'];

        try {
            foreach ($tabs as $tab) {
                $key = sprintf(self::KEY_PROFILE_TAB, $pubkey, $tab);
                $this->redis->del($key);
                $this->redis->del($key . ':compressed');
            }
            $this->logger->debug('Invalidated all profile tabs', ['pubkey' => $pubkey]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate profile tabs', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invalidate all view caches
     */
    public function invalidateAll(): void
    {
        try {
            $this->redis->del(self::KEY_LATEST_ARTICLES);
            $this->redis->del(self::KEY_LATEST_HIGHLIGHTS);
            $this->logger->info('Invalidated all view caches');
        } catch (\Exception $e) {
            $this->logger->error('Failed to invalidate all caches', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Store normalized view objects to Redis
     * @param string $key Redis key
     * @param array<RedisBaseObject> $baseObjects
     * @param int|null $ttl Time to live in seconds
     */
    private function storeView(string $key, array $baseObjects, ?int $ttl = null): void
    {
        try {
            // Normalize all base objects to arrays
            $normalizedObjects = array_map(
                fn(RedisBaseObject $obj) => $this->factory->normalizeBaseObject($obj),
                $baseObjects
            );

            // Encode to JSON
            $json = json_encode($normalizedObjects, JSON_THROW_ON_ERROR);

            // Optionally compress for large payloads
            $data = $this->shouldCompress($json) ? gzcompress($json, 6) : $json;
            $isCompressed = $data !== $json;

            // Store in Redis
            if ($ttl !== null) {
                $this->redis->setex($key, $ttl, $data);
            } else {
                $this->redis->set($key, $data);
            }

            // Store compression flag
            if ($isCompressed) {
                $this->redis->setex($key . ':compressed', $ttl ?? self::DEFAULT_TTL, '1');
            }

            $this->logger->debug('Stored view in Redis', [
                'key' => $key,
                'count' => count($baseObjects),
                'size_bytes' => strlen($data),
                'compressed' => $isCompressed,
                'ttl' => $ttl,
            ]);
        } catch (\JsonException $e) {
            $this->logger->error('JSON encoding failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Failed to store view in Redis', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetch and denormalize view objects from Redis
     * @return array|null Array of arrays (not denormalized to objects) or null if not found
     */
    private function fetchView(string $key): ?array
    {
        try {
            $data = $this->redis->get($key);

            if ($data === false || $data === null) {
                $this->logger->debug('View not found in Redis', ['key' => $key]);
                return null;
            }

            // Check if data is compressed
            $isCompressed = $this->redis->get($key . ':compressed') !== false;

            // Decompress if needed
            if ($isCompressed) {
                $json = gzuncompress($data);
                if ($json === false) {
                    $this->logger->error('Failed to decompress data', ['key' => $key]);
                    return null;
                }
            } else {
                $json = $data;
            }

            // Decode JSON
            $normalized = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            $this->logger->debug('Fetched view from Redis', [
                'key' => $key,
                'count' => count($normalized),
                'compressed' => $isCompressed,
            ]);

            return $normalized;
        } catch (\JsonException $e) {
            $this->logger->error('JSON decoding failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch view from Redis', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Determine if data should be compressed
     * Compress if larger than 10KB
     */
    private function shouldCompress(string $data): bool
    {
        return strlen($data) > 10240; // 10KB
    }

    /**
     * Store all articles from user reading lists as RedisBaseObject[]
     * @param string $pubkey
     * @param RedisReadingListView[] $readingLists
     */
    public function storeUserReadingListArticles(string $pubkey, array $readingLists): void
    {
        $allArticles = [];
        foreach ($readingLists as $list) {
            foreach ($list->articles as $articleObj) {
                if ($articleObj instanceof RedisBaseObject) {
                    $allArticles[] = $articleObj;
                }
            }
        }
        $this->storeUserArticles($pubkey, $allArticles);
    }

    /**
     * Build and cache user reading lists, returning the final view for the template.
     * Handles all DB lookups and stores all articles as RedisBaseObject in Redis.
     *
     * @param EntityManagerInterface $em
     * @param string $pubkey
     * @return RedisReadingListView[]
     */
    public function buildAndCacheUserReadingLists(EntityManagerInterface $em, string $pubkey): array
    {
        $readingLists = $this->factory->buildUserReadingListsView($em, $pubkey);
        $allArticles = [];
        foreach ($readingLists as $list) {
            foreach ($list->articles as $articleObj) {
                $allArticles[] = $articleObj;
            }
        }
        $this->storeUserArticles($pubkey . ':readinglists', $allArticles);
        return $readingLists;
    }
}
