<?php

namespace App\UnfoldBundle\Content;

use App\Service\Graph\GraphLookupService;
use App\Service\Nostr\NostrClient;
use App\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use App\UnfoldBundle\Config\SiteConfig;
use Psr\Log\LoggerInterface;

/**
 * Provides content by traversing the magazine event tree.
 *
 * Primary path: GraphLookupService (local DB via parsed_reference + current_record).
 * Fallback path: NostrClient relay round-trips (used when graph data is missing).
 *
 * The graph path resolves the entire magazine tree in a single recursive SQL query,
 * eliminating the N+1 relay requests that caused slow cache warming and first-visit failures.
 */
class ContentProvider
{
    private const FRESH_TTL = 300;   // 5 minutes - serve without revalidation
    private const STALE_TTL = 3600;  // 1 hour - serve stale while revalidating

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly StaleWhileRevalidateCache $swrCache,
        private readonly LoggerInterface $logger,
        private readonly ?GraphLookupService $graphLookup = null,
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
        if (empty($site->categories)) {
            return [];
        }

        // Try graph-backed fast path first
        if ($this->graphLookup !== null) {
            $result = $this->fetchCategoriesFromGraph($site);
            if (!empty($result)) {
                return $result;
            }
            $this->logger->info('Graph path returned empty categories, falling back to relay', [
                'naddr' => $site->naddr,
            ]);
        }

        // Fallback: relay round-trips
        return $this->fetchCategoriesFromRelay($site);
    }

    /**
     * Fetch categories using the graph layer (local DB).
     * @return CategoryData[]
     */
    private function fetchCategoriesFromGraph(SiteConfig $site): array
    {
        $children = $this->graphLookup->resolveChildren($site->naddr);

        if (empty($children)) {
            return [];
        }

        // Collect event IDs and fetch rows in one query
        $eventIds = array_column($children, 'current_event_id');
        $eventRows = $this->graphLookup->fetchEventRows($eventIds);

        // Build a coord→child map
        $childByCoord = [];
        foreach ($children as $child) {
            $childByCoord[$child['coord']] = $child;
        }

        // Build CategoryData, preserving category order from site config
        $categories = [];
        foreach ($site->categories as $coordinate) {
            $child = $this->findChildByCoord($childByCoord, $coordinate);

            if ($child === null) {
                $this->logger->debug('Category not found in graph children', ['coordinate' => $coordinate]);
                continue;
            }

            $eventRow = $eventRows[$child['current_event_id']] ?? null;
            if ($eventRow === null) {
                continue;
            }

            $categories[] = CategoryData::fromEvent($this->rowToEventObject($eventRow), $coordinate);
        }

        if (!empty($categories)) {
            $this->logger->debug('Categories resolved from graph', ['count' => count($categories)]);
        }

        return $categories;
    }

    /**
     * Fetch categories via relay round-trips (original fallback).
     * @return CategoryData[]
     */
    private function fetchCategoriesFromRelay(SiteConfig $site): array
    {
        $eventsMap = $this->nostrClient->getEventsByCoordinates($site->categories);

        $categories = [];
        foreach ($site->categories as $coordinate) {
            if (!isset($eventsMap[$coordinate])) {
                $this->logger->warning('Category event not found (batch)', ['coordinate' => $coordinate]);
                continue;
            }
            $categories[] = CategoryData::fromEvent($eventsMap[$coordinate], $coordinate);
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
        // Try graph-backed fast path first
        if ($this->graphLookup !== null) {
            $result = $this->fetchCategoryPostsFromGraph($categoryCoordinate);
            if (!empty($result)) {
                return $result;
            }
            $this->logger->info('Graph path returned empty posts, falling back to relay', [
                'coordinate' => $categoryCoordinate,
            ]);
        }

        // Fallback: relay round-trips
        return $this->fetchCategoryPostsFromRelay($categoryCoordinate);
    }

    /**
     * Fetch category posts using the graph layer (local DB).
     * @return PostData[]
     */
    private function fetchCategoryPostsFromGraph(string $categoryCoordinate): array
    {
        $children = $this->graphLookup->resolveChildren($categoryCoordinate);

        if (empty($children)) {
            return [];
        }

        $eventIds = array_column($children, 'current_event_id');
        $eventRows = $this->graphLookup->fetchEventRows($eventIds);

        $posts = [];
        foreach ($children as $child) {
            $eventRow = $eventRows[$child['current_event_id']] ?? null;
            if ($eventRow === null) {
                continue;
            }
            $posts[] = PostData::fromEvent($this->rowToEventObject($eventRow));
        }

        if (!empty($posts)) {
            $this->logger->debug('Category posts resolved from graph', [
                'coordinate' => $categoryCoordinate,
                'count' => count($posts),
            ]);
        }

        return $posts;
    }

    /**
     * Fetch category posts via relay round-trips (original fallback).
     * @return PostData[]
     */
    private function fetchCategoryPostsFromRelay(string $categoryCoordinate): array
    {
        $category = $this->fetchCategoryByCoordinate($categoryCoordinate);
        if ($category === null) {
            return [];
        }

        if (empty($category->articleCoordinates)) {
            return [];
        }

        $eventsMap = $this->nostrClient->getEventsByCoordinates($category->articleCoordinates);

        $posts = [];
        foreach ($category->articleCoordinates as $articleCoordinate) {
            if (!isset($eventsMap[$articleCoordinate])) {
                $this->logger->warning('Post event not found (batch)', ['coordinate' => $articleCoordinate]);
                continue;
            }
            $posts[] = PostData::fromEvent($eventsMap[$articleCoordinate]);
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
        // Try graph-backed fast path: search through all descendants
        if ($this->graphLookup !== null) {
            $post = $this->fetchPostBySlugFromGraph($slug, $site);
            if ($post !== null) {
                return $post;
            }
        }

        // Fallback: search through all categories via cache/relay
        $categories = $this->getCategories($site);

        foreach ($categories as $category) {
            foreach ($category->articleCoordinates as $coordinate) {
                if (str_ends_with($coordinate, ':' . $slug)) {
                    return $this->fetchPostByCoordinate($coordinate);
                }
            }
        }

        return null;
    }

    /**
     * Find a post by slug using the graph layer.
     */
    private function fetchPostBySlugFromGraph(string $slug, SiteConfig $site): ?PostData
    {
        $descendants = $this->graphLookup->resolveDescendants($site->naddr, 3);

        foreach ($descendants as $desc) {
            if (str_ends_with($desc['coord'], ':' . $slug)) {
                $eventRows = $this->graphLookup->fetchEventRows([$desc['current_event_id']]);
                $eventRow = $eventRows[$desc['current_event_id']] ?? null;
                if ($eventRow !== null) {
                    return PostData::fromEvent($this->rowToEventObject($eventRow));
                }
            }
        }

        return null;
    }

    /**
     * Fetch a category event by coordinate (relay fallback path)
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
     * Fetch a post event by coordinate (relay fallback path)
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
     * Convert a database row array to a stdClass event object matching NostrClient format.
     */
    private function rowToEventObject(array $row): object
    {
        $tags = $row['tags'] ?? '[]';
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }

        return (object) [
            'id' => $row['id'],
            'pubkey' => $row['pubkey'],
            'kind' => (int) $row['kind'],
            'content' => $row['content'] ?? '',
            'tags' => $tags,
            'created_at' => (int) ($row['created_at'] ?? 0),
            'sig' => $row['sig'] ?? '',
        ];
    }

    /**
     * Find a child record by coordinate, handling case differences in pubkeys.
     */
    private function findChildByCoord(array $childByCoord, string $coordinate): ?array
    {
        // Exact match
        if (isset($childByCoord[$coordinate])) {
            return $childByCoord[$coordinate];
        }

        // Case-insensitive match (pubkey case normalization differences)
        $lower = strtolower($coordinate);
        if (isset($childByCoord[$lower])) {
            return $childByCoord[$lower];
        }

        foreach ($childByCoord as $k => $v) {
            if (strcasecmp($k, $coordinate) === 0) {
                return $v;
            }
        }

        return null;
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

