<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use Psr\Log\LoggerInterface;

/**
 * Signed index references determine membership and order. The optional graph
 * supplies local events, while the gateway fills only missing exact identities.
 */
class ContentProvider
{
    private const CACHE_VERSION = 'v4_';
    private const FRESH_TTL = 300;
    private const STALE_TTL = 3600;
    private const REVISION_TTL = 31536000;

    public function __construct(
        private readonly EventReadGatewayInterface $eventGateway,
        private readonly StaleWhileRevalidateCache $swrCache,
        private readonly LoggerInterface $logger,
        private readonly ?PublicationTreeLookupInterface $treeLookup = null,
    ) {}

    /** @return list<CategoryData> */
    public function getCategories(SiteConfig $site): array
    {
        if ($site->isScoped) {
            return [];
        }
        return $this->swrCache->get(
            $this->categoriesKey($site),
            fn() => $this->fetchCategories($site),
            self::FRESH_TTL,
            self::STALE_TTL,
            [],
        );
    }

    /** @return list<CategoryData> */
    private function fetchCategories(SiteConfig $site): array
    {
        $references = array_values(array_unique(array_map($this->normalizeCoordinate(...), $site->categories)));
        $events = $this->resolveReferences($references, $site->naddr);
        $categories = [];
        foreach ($references as $coordinate) {
            $event = $events[$coordinate] ?? null;
            if ($event !== null && $event->kind === 30040 && !ContentReference::isScoped($event)) {
                $categories[] = CategoryData::fromEvent($event, $coordinate);
            }
        }

        return $categories;
    }

    /** @return list<PostData> */
    public function getCategoryPosts(string $categoryCoordinate): array
    {
        $categoryCoordinate = $this->normalizeCoordinate($categoryCoordinate);
        return $this->readCategoryPosts($categoryCoordinate, []);
    }

    /** @param list<string> $ancestors @return list<PostData> */
    private function readCategoryPosts(string $coordinate, array $ancestors): array
    {
        if (count($ancestors) >= 3 || in_array($coordinate, $ancestors, true)) {
            return [];
        }
        $entries = $this->swrCache->get(
            $this->categoryKey($coordinate),
            fn() => $this->fetchCategoryEntries($coordinate),
            self::FRESH_TTL,
            self::STALE_TTL,
            [],
        );
        $posts = [];
        foreach ($entries as $entry) {
            if ($entry instanceof PostData && $entry->isPublic()) {
                $posts[$entry->coordinate] ??= $entry;
            } elseif (is_string($entry)) {
                foreach ($this->readCategoryPosts($entry, [...$ancestors, $coordinate]) as $post) {
                    $posts[$post->coordinate] ??= $post;
                }
            }
        }

        return array_values($posts);
    }

    /** @return list<PostData|string> Direct leaves or child-index coordinates in signed order. */
    private function fetchCategoryEntries(string $coordinate): array
    {
        // A graph child collection alone cannot establish the current signed
        // index's scope or membership, including when projection is incomplete.
        $event = $this->lookup($coordinate);
        if ($event === null || $event->kind !== 30040 || ContentReference::isScoped($event)
            || $this->eventCoordinate($event) !== $coordinate) {
            return [];
        }
        $category = CategoryData::fromEvent($event, $coordinate);
        $events = $this->resolveReferences($category->referenceCoordinates, $coordinate);
        $entries = [];
        foreach ($category->referenceCoordinates as $reference) {
            $child = $events[$reference] ?? null;
            if ($child === null || ContentReference::isScoped($child)) {
                continue;
            }
            if ($child->kind === 30040) {
                // Cache the reference, not a flattened descendant snapshot:
                // nested shared-category revisions remain independently live.
                $entries[] = $reference;
                continue;
            }
            try {
                $identity = ContentReference::fromInput($reference);
                if ($identity->matches($child)) {
                    $entries[] = PostData::fromEvent($child);
                }
            } catch (\InvalidArgumentException) {
            }
        }

        return $entries;
    }

    /**
     * A present but denied/mismatched event is not a missing event: never retry
     * its coordinate through a less restrictive fallback.
     *
     * @param list<string> $references
     * @return array<string, NostrEvent>
     */
    private function resolveReferences(array $references, string $parent): array
    {
        if ($references === []) {
            return [];
        }
        $events = [];
        try {
            foreach ($this->treeLookup?->findChildren($parent) ?? [] as $event) {
                $coordinate = $this->eventCoordinate($event);
                if (in_array($coordinate, $references, true)) {
                    $events[$coordinate] ??= $event;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Publication graph lookup failed', ['coordinate' => $parent, 'error' => $e->getMessage()]);
        }
        $missing = array_values(array_diff($references, array_keys($events)));
        if ($missing !== []) {
            try {
                foreach ($this->eventGateway->findByCoordinates($missing) as $key => $event) {
                    if (!$event instanceof NostrEvent) {
                        continue;
                    }
                    $coordinate = $this->eventCoordinate($event);
                    // Do not trust either a gateway map key or a slug-only match.
                    if (in_array($coordinate, $missing, true)
                        && (!is_string($key) || $this->normalizeCoordinate($key) === $coordinate)) {
                        $events[$coordinate] ??= $event;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Publication reference hydration failed', ['coordinate' => $parent, 'error' => $e->getMessage()]);
            }
        }

        return $events;
    }

    /** @return list<PostData> */
    public function getHomePosts(SiteConfig $site, int $limit = 3): array
    {
        return $this->publicationInventory($site, max(0, $limit));
    }

    /** @return list<PostData> */
    public function getPublicationPosts(SiteConfig $site, int $limit = 50): array
    {
        $posts = $this->publicationInventory($site);
        // Stable sort retains signed index order for equal timestamps.
        usort($posts, static fn(PostData $a, PostData $b): int => $b->publishedAt <=> $a->publishedAt);

        return array_slice($posts, 0, max(0, min(50, $limit)));
    }

    /** @return list<PostData> All public leaves in signed traversal order, without a discovery cap. */
    public function getPublicationInventory(SiteConfig $site): array
    {
        return $this->publicationInventory($site);
    }

    /** @return list<PostData> */
    private function publicationInventory(SiteConfig $site, ?int $perCategoryLimit = null): array
    {
        if ($site->isScoped) {
            return [];
        }
        $posts = [];
        $categories = [];
        foreach ($this->getCategories($site) as $category) {
            $categories[$category->coordinate] = $category;
        }
        $rootPosts = [];
        foreach ($this->getRootPosts($site) as $post) {
            $rootPosts[$post->coordinate] = $post;
        }
        $references = $site->rootReferenceCoordinates !== []
            ? $site->rootReferenceCoordinates
            : [...array_keys($rootPosts), ...array_keys($categories)];
        $directCount = 0;
        foreach ($references as $reference) {
            $reference = $this->normalizeCoordinate($reference);
            if (isset($rootPosts[$reference])) {
                if ($perCategoryLimit === null || $directCount++ < $perCategoryLimit) {
                    $posts[$reference] ??= $rootPosts[$reference];
                }
                continue;
            }
            $category = $categories[$reference] ?? null;
            if ($category === null) {
                continue;
            }
            $categoryPosts = $this->getCategoryPosts($category->coordinate);
            if ($perCategoryLimit !== null) {
                $categoryPosts = array_slice($categoryPosts, 0, $perCategoryLimit);
            }
            foreach ($categoryPosts as $post) {
                if ($post->isPublic()) {
                    $posts[$post->coordinate] ??= $post;
                }
            }
        }

        return array_values($posts);
    }

    /** @return list<PostData> */
    private function getRootPosts(SiteConfig $site): array
    {
        $references = $site->rootContentCoordinates !== [] ? $site->rootContentCoordinates : $site->rootArticleCoordinates;
        if ($site->isScoped || $references === []) {
            return [];
        }

        return $this->swrCache->get(
            $this->rootPostsKey($site),
            function () use ($site, $references): array {
                $references = array_values(array_unique(array_map($this->normalizeCoordinate(...), $references)));
                $events = $this->resolveReferences($references, $site->naddr);
                $posts = [];
                foreach ($references as $coordinate) {
                    $event = $events[$coordinate] ?? null;
                    if ($event === null || ContentReference::isScoped($event)) {
                        continue;
                    }
                    try {
                        $reference = ContentReference::fromInput($coordinate);
                        if ($reference->matches($event)) {
                            $posts[] = PostData::fromEvent($event);
                        }
                    } catch (\InvalidArgumentException) {
                    }
                }

                return $posts;
            },
            self::FRESH_TTL,
            self::STALE_TTL,
            [],
        );
    }

    public function getPostByCoordinate(string $coordinate, SiteConfig $site): ?PostData
    {
        try {
            $identity = ContentReference::fromInput($coordinate);
        } catch (\InvalidArgumentException) {
            return null;
        }
        foreach ($this->publicationInventory($site) as $post) {
            if ($post->coordinate === $identity->coordinate) {
                return $post;
            }
        }

        return null;
    }

    /** Legacy articles only: an ambiguous identifier must never select an author. */
    public function getPost(string $slug, SiteConfig $site): ?PostData
    {
        $matches = array_values(array_filter(
            $this->publicationInventory($site),
            static fn(PostData $post): bool => $post->kind === 30023 && $post->slug === $slug,
        ));
        if (count($matches) > 1) {
            throw new AmbiguousContentException($matches);
        }

        return $matches[0] ?? null;
    }

    public function getAboutArticle(SiteConfig $site): ?PostData
    {
        if ($site->isScoped) {
            return null;
        }
        $coordinate = $site->aboutArticleCoordinate;
        $relayHints = $site->aboutRelayHints;
        if ($coordinate === null) {
            $references = array_values(array_unique($site->rootArticleCoordinates));
            if (count($references) !== 1) {
                return null;
            }
            $coordinate = $references[0];
            $relayHints = [];
        }
        try {
            $reference = ContentReference::fromInput($coordinate);
            $event = $this->lookup($coordinate, $relayHints);

            return $reference->kind === 30023 && $event !== null
                && !ContentReference::isScoped($event) && $reference->matches($event)
                ? PostData::fromEvent($event) : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** @param list<CategoryData> $categories @return list<string> */
    public function getCategoryArticleAuthorPubkeys(array $categories): array
    {
        $authors = [];
        foreach ($categories as $category) {
            foreach ($this->getCategoryPosts($category->coordinate) as $post) {
                if ($post->isPublic()) {
                    $authors[$post->pubkey] = true;
                }
            }
        }

        return array_keys($authors);
    }

    private function lookup(string $coordinate, array $relayHints = []): ?NostrEvent
    {
        try {
            return $this->eventGateway->findByCoordinate($coordinate, $relayHints);
        } catch (\Throwable $e) {
            $this->logger->warning('Publication event lookup failed', ['coordinate' => $coordinate, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function eventCoordinate(NostrEvent $event): string
    {
        $identifier = null;
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'd') {
                if ($identifier !== null || !is_string($tag[1] ?? null)) {
                    return '';
                }
                $identifier = $tag[1];
            }
        }

        return $identifier === null ? '' : $event->kind . ':' . strtolower($event->pubkey) . ':' . $identifier;
    }

    private function normalizeCoordinate(string $coordinate): string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) === 3) {
            $parts[1] = strtolower($parts[1]);
        }

        return implode(':', $parts);
    }

    private function categoriesKey(SiteConfig $site): string
    {
        $dependencies = [];
        foreach ($site->categories as $coordinate) {
            $coordinate = $this->normalizeCoordinate($coordinate);
            $dependencies[$coordinate] = $this->categoryRevision($coordinate);
        }

        return self::CACHE_VERSION . 'categories_' . hash('sha256', $site->naddr . json_encode($dependencies));
    }

    private function categoryKey(string $coordinate): string
    {
        return self::CACHE_VERSION . 'category_posts_' . md5($coordinate) . '_' . $this->categoryRevision($coordinate);
    }

    private function rootPostsKey(SiteConfig $site): string
    {
        return self::CACHE_VERSION . 'root_posts_' . hash('sha256', serialize([
            $site->naddr, $site->rootContentCoordinates, $site->rootArticleCoordinates,
        ]));
    }

    private function categoryRevision(string $coordinate): string
    {
        return $this->swrCache->get(
            self::CACHE_VERSION . 'category_revision_' . md5($coordinate),
            static fn(): string => bin2hex(random_bytes(16)),
            self::REVISION_TTL,
            self::REVISION_TTL,
        );
    }

    public function invalidateCategoryCache(string $coordinate): void
    {
        $coordinate = $this->normalizeCoordinate($coordinate);
        $this->swrCache->invalidate($this->categoryKey($coordinate));
        // Every publication's category inventory key depends on this shared
        // revision. No registry/database lookup or host-specific coupling.
        $this->swrCache->invalidate(self::CACHE_VERSION . 'category_revision_' . md5($coordinate));
    }

    public function invalidateSiteCache(SiteConfig $site): void
    {
        $this->swrCache->invalidate($this->categoriesKey($site));
        $this->swrCache->invalidate($this->rootPostsKey($site));
        foreach ($site->categories as $coordinate) {
            $this->invalidateCategoryCache($coordinate);
        }
        // Aggregate lists are derived from the shared category caches rather
        // than separately cached, so shared-category updates reach every site.
    }
}
