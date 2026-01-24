<?php

namespace App\UnfoldBundle\Content;

use App\Service\Nostr\NostrClient;
use App\UnfoldBundle\Config\SiteConfig;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides content by traversing the magazine event tree
 */
class ContentProvider
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly CacheItemPoolInterface $unfoldCache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get all categories for a site
     *
     * @return CategoryData[]
     */
    public function getCategories(SiteConfig $site): array
    {
        $cacheKey = 'categories_' . md5($site->naddr);
        $cacheItem = $this->unfoldCache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $categories = [];

        foreach ($site->categories as $coordinate) {
            $category = $this->fetchCategoryByCoordinate($coordinate);
            if ($category !== null) {
                $categories[] = $category;
            }
        }

        $cacheItem->set($categories);
        $cacheItem->expiresAfter(self::CACHE_TTL);
        $this->unfoldCache->save($cacheItem);

        return $categories;
    }

    /**
     * Get posts for a specific category
     *
     * @return PostData[]
     */
    public function getCategoryPosts(string $categoryCoordinate): array
    {
        $cacheKey = 'category_posts_' . md5($categoryCoordinate);
        $cacheItem = $this->unfoldCache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        // First fetch the category to get article coordinates
        $category = $this->fetchCategoryByCoordinate($categoryCoordinate);
        if ($category === null) {
            return [];
        }

        $posts = [];
        foreach ($category->articleCoordinates as $articleCoordinate) {
            $post = $this->fetchPostByCoordinate($articleCoordinate);
            if ($post !== null) {
                $posts[] = $post;
            }
        }

        // Sort by published date descending
        usort($posts, fn(PostData $a, PostData $b) => $b->publishedAt <=> $a->publishedAt);

        $cacheItem->set($posts);
        $cacheItem->expiresAfter(self::CACHE_TTL);
        $this->unfoldCache->save($cacheItem);

        return $posts;
    }

    /**
     * Get all posts for the home page (aggregated from all categories)
     *
     * @return PostData[]
     */
    public function getHomePosts(SiteConfig $site, int $limit = 10): array
    {
        $cacheKey = 'home_posts_' . md5($site->naddr) . '_' . $limit;
        $cacheItem = $this->unfoldCache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }

        $allPosts = [];
        $categories = $this->getCategories($site);

        foreach ($categories as $category) {
            $categoryPosts = $this->getCategoryPosts($category->coordinate);
            $allPosts = array_merge($allPosts, $categoryPosts);
        }

        // Remove duplicates by coordinate
        $uniquePosts = [];
        foreach ($allPosts as $post) {
            $uniquePosts[$post->coordinate] = $post;
        }
        $allPosts = array_values($uniquePosts);

        // Sort by published date descending and limit
        usort($allPosts, fn(PostData $a, PostData $b) => $b->publishedAt <=> $a->publishedAt);
        $posts = array_slice($allPosts, 0, $limit);

        $cacheItem->set($posts);
        $cacheItem->expiresAfter(self::CACHE_TTL);
        $this->unfoldCache->save($cacheItem);

        return $posts;
    }

    /**
     * Get a single post by slug
     */
    public function getPost(string $slug, SiteConfig $site): ?PostData
    {
        // Search through all categories for the post
        $categories = $this->getCategories($site);

        foreach ($categories as $category) {
            foreach ($category->articleCoordinates as $coordinate) {
                // Check if coordinate ends with the slug
                if (str_ends_with($coordinate, ':' . $slug)) {
                    return $this->fetchPostByCoordinate($coordinate);
                }
            }
        }

        return null;
    }

    /**
     * Fetch a category event by coordinate
     */
    private function fetchCategoryByCoordinate(string $coordinate): ?CategoryData
    {
        $decoded = $this->parseCoordinate($coordinate);
        if ($decoded === null) {
            $this->logger->warning('Invalid category coordinate', ['coordinate' => $coordinate]);
            return null;
        }

        try {
            $event = $this->nostrClient->getEventByNaddr($decoded);
            if ($event === null) {
                $this->logger->warning('Category event not found', ['coordinate' => $coordinate]);
                return null;
            }

            return CategoryData::fromEvent($event, $coordinate);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching category', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch a post event by coordinate
     */
    private function fetchPostByCoordinate(string $coordinate): ?PostData
    {
        $decoded = $this->parseCoordinate($coordinate);
        if ($decoded === null) {
            $this->logger->warning('Invalid post coordinate', ['coordinate' => $coordinate]);
            return null;
        }

        try {
            $event = $this->nostrClient->getEventByNaddr($decoded);
            if ($event === null) {
                $this->logger->warning('Post event not found', ['coordinate' => $coordinate]);
                return null;
            }

            return PostData::fromEvent($event);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching post', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
            return null;
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
            'pubkey' => $parts[1],
            'identifier' => $parts[2],
            'relays' => [],
        ];
    }

    /**
     * Invalidate all caches for a site
     */
    public function invalidateSiteCache(SiteConfig $site): void
    {
        $this->unfoldCache->deleteItem('categories_' . md5($site->naddr));
        $this->unfoldCache->deleteItem('home_posts_' . md5($site->naddr) . '_10');

        foreach ($site->categories as $coordinate) {
            $this->unfoldCache->deleteItem('category_posts_' . md5($coordinate));
        }

        $this->logger->info('Invalidated site content cache', ['naddr' => $site->naddr]);
    }
}

