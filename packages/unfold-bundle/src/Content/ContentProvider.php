<?php

namespace DecentNewsroom\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides content by traversing the magazine event tree.
 *
 * Primary path: the optional publication-tree lookup (host graph adapter).
 * Fallback path: the event gateway's relay reads (used when graph data is missing).
 *
 * The graph path resolves the entire magazine tree in a single recursive SQL query,
 * eliminating the N+1 relay requests that caused slow cache warming and first-visit failures.
 */
class ContentProvider
{
    private const FRESH_TTL = 300;   // 5 minutes - serve without revalidation
    private const STALE_TTL = 3600;  // 1 hour - serve stale while revalidating

    public function __construct(
        private readonly EventReadGatewayInterface $eventGateway,
        private readonly StaleWhileRevalidateCache $swrCache,
        private readonly LoggerInterface $logger,
        private readonly ?PublicationTreeLookupInterface $treeLookup = null,
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
        if ($this->treeLookup !== null) {
            $result = $this->fetchCategoriesFromGraph($site);
            if (!empty($result)) {
                return $result;
            }
            $this->logger->warning('Graph path returned empty categories, falling back to relay', [
                'naddr' => $site->naddr,
                'expected_categories' => $site->categories,
            ]);
        }

        // Fallback: relay round-trips (wrapped to prevent silent hangs)
        try {
            return $this->fetchCategoriesFromRelay($site);
        } catch (\Throwable $e) {
            $this->logger->error('Relay fallback failed for categories, returning empty', [
                'naddr' => $site->naddr,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch categories using the graph layer (local DB).
     * @return CategoryData[]
     */
    private function fetchCategoriesFromGraph(SiteConfig $site): array
    {
        $this->logger->debug('Resolving categories from graph', [
            'naddr' => $site->naddr,
            'category_count' => count($site->categories),
        ]);

        $children = $this->treeLookup->findChildren($site->naddr);

        if (empty($children)) {
            return [];
        }

        // Build a coord→event map while retaining the configured category order.
        $childByCoord = [];
        foreach ($children as $child) {
            $childByCoord[$this->eventCoordinate($child)] = $child;
        }

        // Build CategoryData, preserving category order from site config
        $categories = [];
        foreach ($site->categories as $coordinate) {
            $child = $this->findChildByCoord($childByCoord, $coordinate);

            if ($child === null) {
                $this->logger->debug('Category not found in graph children', ['coordinate' => $coordinate]);
                continue;
            }

            $categories[] = CategoryData::fromEvent($child, $coordinate);
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
        $eventsMap = $this->eventGateway->findByCoordinates($site->categories);

        $categories = [];
        foreach ($site->categories as $coordinate) {
            $event = $this->findEvent($eventsMap, $coordinate);
            if ($event === null) {
                $this->logger->warning('Category event not found (batch)', ['coordinate' => $coordinate]);
                continue;
            }
            $categories[] = CategoryData::fromEvent($event, $coordinate);
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
        if ($this->treeLookup !== null) {
            $result = $this->fetchCategoryPostsFromGraph($categoryCoordinate);
            if (!empty($result)) {
                return $result;
            }
            $this->logger->warning('Graph path returned empty posts, falling back to relay', [
                'coordinate' => $categoryCoordinate,
            ]);
        }

        // Fallback: relay round-trips (wrapped to prevent silent hangs)
        try {
            return $this->fetchCategoryPostsFromRelay($categoryCoordinate);
        } catch (\Throwable $e) {
            $this->logger->error('Relay fallback failed for category posts, returning empty', [
                'coordinate' => $categoryCoordinate,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch category posts using the graph layer (local DB).
     * @return PostData[]
     */
    private function fetchCategoryPostsFromGraph(string $categoryCoordinate): array
    {
        $children = $this->treeLookup->findChildren($categoryCoordinate);

        if (empty($children)) {
            return [];
        }

        $posts = [];
        foreach ($children as $child) {
            $posts[] = PostData::fromEvent($child);
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

        $eventsMap = $this->eventGateway->findByCoordinates($category->articleCoordinates);

        $posts = [];
        foreach ($category->articleCoordinates as $articleCoordinate) {
            $event = $this->findEvent($eventsMap, $articleCoordinate);
            if ($event === null) {
                $this->logger->warning('Post event not found (batch)', ['coordinate' => $articleCoordinate]);
                continue;
            }
            $posts[] = PostData::fromEvent($event);
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
     * Get the newest posts in a publication, across every category.
     *
     * Unlike getHomePosts(), this deliberately fetches the complete category
     * collections before applying one publication-wide limit. This makes the
     * result suitable for discovery documents such as RSS and sitemaps.
     *
     * @return PostData[]
     */
    public function getPublicationPosts(SiteConfig $site, int $limit = 50): array
    {
        $limit = max(0, min(50, $limit));
        $cacheKey = 'publication_posts_' . md5($site->naddr) . '_' . $limit;

        return $this->swrCache->get(
            $cacheKey,
            fn() => $this->fetchPublicationPostsInternal($site, $limit),
            self::FRESH_TTL,
            self::STALE_TTL,
            []
        );
    }

    /**
     * @return PostData[]
     */
    private function fetchPublicationPostsInternal(SiteConfig $site, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        $postsByCoordinate = [];
        $order = 0;

        foreach ($this->getCategories($site) as $category) {
            foreach ($this->getCategoryPosts($category->coordinate) as $post) {
                $coordinate = strtolower($post->coordinate);
                if (isset($postsByCoordinate[$coordinate])) {
                    continue;
                }

                $postsByCoordinate[$coordinate] = [
                    'post' => $post,
                    'order' => $order++,
                ];
            }
        }

        uasort(
            $postsByCoordinate,
            static function (array $left, array $right): int {
                $publishedAt = $right['post']->publishedAt <=> $left['post']->publishedAt;

                return $publishedAt !== 0
                    ? $publishedAt
                    : $left['order'] <=> $right['order'];
            }
        );

        return array_values(array_map(
            static fn(array $entry): PostData => $entry['post'],
            array_slice($postsByCoordinate, 0, $limit)
        ));
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
        if ($this->treeLookup !== null) {
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
        $descendants = $this->treeLookup->findDescendants($site->naddr, 3);

        foreach ($descendants as $desc) {
            if ($this->eventIdentifier($desc) === $slug) {
                return PostData::fromEvent($desc);
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
            $event = $this->eventGateway->findByCoordinate($coordinate);
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
            $event = $this->eventGateway->findByCoordinate($coordinate);
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
     * Find a child record by coordinate, handling case differences in pubkeys.
     */
    private function findChildByCoord(array $childByCoord, string $coordinate): ?NostrEvent
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

    private function findEvent(array $events, string $coordinate): ?NostrEvent
    {
        $normalized = $this->normalizeCoordinate($coordinate);
        foreach ($events as $eventCoordinate => $event) {
            if ($event instanceof NostrEvent
                && ($eventCoordinate === $normalized || $this->normalizeCoordinate((string) $eventCoordinate) === $normalized)
            ) {
                return $event;
            }
        }

        return null;
    }

    private function eventCoordinate(NostrEvent $event): string
    {
        return sprintf('%d:%s:%s', $event->kind, strtolower($event->pubkey), $this->eventIdentifier($event));
    }

    private function eventIdentifier(NostrEvent $event): string
    {
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'd' && isset($tag[1])) {
                return $tag[1];
            }
        }

        return '';
    }

    private function normalizeCoordinate(string $coordinate): string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return strtolower($coordinate);
        }
        $parts[1] = strtolower($parts[1]);

        return implode(':', $parts);
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
     * Invalidate all caches for a site
     */
    public function invalidateSiteCache(SiteConfig $site): void
    {
        $this->swrCache->invalidate('categories_' . md5($site->naddr));
        $this->swrCache->invalidate('home_posts_' . md5($site->naddr) . '_10');
        $this->swrCache->invalidate('home_posts_' . md5($site->naddr) . '_3');
        $this->swrCache->invalidate('publication_posts_' . md5($site->naddr) . '_50');

        foreach ($site->categories as $coordinate) {
            $this->swrCache->invalidate('category_posts_' . md5($coordinate));
        }

        $this->logger->info('Invalidated site content cache', ['naddr' => $site->naddr]);
    }
}
