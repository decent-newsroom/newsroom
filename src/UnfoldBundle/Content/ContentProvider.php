<?php

namespace App\UnfoldBundle\Content;

use App\Service\Nostr\NostrClient;
use App\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use App\UnfoldBundle\Config\SiteConfig;
use Psr\Log\LoggerInterface;

/**
 * Provides content by traversing the magazine event tree
 *
 * Uses stale-while-revalidate caching to prevent timeouts:
 * - Fresh cache (< 5 min): serve immediately
 * - Stale cache (< 1 hour): serve immediately, refresh in background
 * - Expired cache (> 1 hour): try refresh with fallback to stale
 */
class ContentProvider
{
    private const FRESH_TTL = 300;   // 5 minutes - serve without revalidation
    private const STALE_TTL = 3600;  // 1 hour - serve stale while revalidating

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly StaleWhileRevalidateCache $swrCache,
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

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchAllCategories($site),
            self::FRESH_TTL,
            self::STALE_TTL,
            [] // Return empty array on failure
        );
    }

    /**
     * Fetch all categories (internal fetcher for cache)
     * @return CategoryData[]
     */
    private function fetchAllCategories(SiteConfig $site): array
    {
        $categories = [];

        foreach ($site->categories as $coordinate) {
            $category = $this->fetchCategoryByCoordinate($coordinate);
            if ($category !== null) {
                $categories[] = $category;
            }
        }


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

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchCategoryPostsInternal($categoryCoordinate),
            self::FRESH_TTL,
            self::STALE_TTL,
            [] // Return empty array on failure
        );
    }

    /**
     * Fetch category posts (internal fetcher for cache)
     * @return PostData[]
     */
    private function fetchCategoryPostsInternal(string $categoryCoordinate): array
    {
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


        return $posts;
    }

    /**
     * Get all posts for the home page (aggregated from all categories)
     *
     * @return PostData[]
     */
    public function getHomePosts(SiteConfig $site, int $limit = 3): array
    {
        $cacheKey = 'home_posts_' . md5($site->naddr) . '_' . $limit;

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchHomePostsInternal($site, $limit),
            self::FRESH_TTL,
            self::STALE_TTL,
            [] // Return empty array on failure
        );
    }

    /**
     * Fetch home posts (internal fetcher for cache)
     * @return PostData[]
     */
    private function fetchHomePostsInternal(SiteConfig $site, int $limit): array
    {
        $allPosts = [];
        $categories = $this->getCategories($site);

        foreach ($categories as $category) {
            $categoryPosts = $this->getCategoryPosts($category->coordinate);
            $allPosts = array_merge($allPosts, array_slice($categoryPosts, 0, $limit));
        }


        return $allPosts;
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
        $this->swrCache->invalidate('categories_' . md5($site->naddr));
        $this->swrCache->invalidate('home_posts_' . md5($site->naddr) . '_10');
        $this->swrCache->invalidate('home_posts_' . md5($site->naddr) . '_3');

        foreach ($site->categories as $coordinate) {
            $this->swrCache->invalidate('category_posts_' . md5($coordinate));
        }

        $this->logger->info('Invalidated site content cache', ['naddr' => $site->naddr]);
    }
}

