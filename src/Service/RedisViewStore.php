<?php

namespace App\Service;

use App\ReadModel\RedisView\RedisViewFactory;
use App\ReadModel\RedisView\RedisBaseObject;
use App\ReadModel\RedisView\RedisReadingListView;
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

    private const DEFAULT_TTL = 3600; // 1 hour

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
