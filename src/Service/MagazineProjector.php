<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\Magazine;
use App\Repository\MagazineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects Nostr magazine indices (kind 30040) into Magazine entities
 * Recursively traverses magazine structure to extract all nested data
 */
class MagazineProjector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MagazineRepository $magazineRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Project a magazine from its slug
     *
     * @param string $slug Magazine slug (d tag value)
     * @return Magazine|null The projected magazine or null if not found
     */
    public function projectMagazine(string $slug): ?Magazine
    {
        $this->logger->info('Starting magazine projection', ['slug' => $slug]);

        // Find the magazine index event by slug
        $magazineEvent = $this->findEventBySlug($slug);

        if (!$magazineEvent) {
            $this->logger->warning('Magazine event not found', ['slug' => $slug]);
            return null;
        }

        // Check if this is actually a magazine (has 'a' tags pointing to 30040 categories)
        $tags = $magazineEvent->getTags();
        $isMagazine = false;
        foreach ($tags as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === 'a' && str_starts_with((string)$tag[1], '30040:')) {
                $isMagazine = true;
                break;
            }
        }

        if (!$isMagazine) {
            $this->logger->warning('Event is not a magazine index', ['slug' => $slug]);
            return null;
        }

        // Extract basic magazine metadata
        $magazine = $this->magazineRepository->findBySlug($slug);
        if (!$magazine) {
            $magazine = new Magazine();
            $magazine->setSlug($slug);
        }

        $magazine->setTitle($this->getTagValue($tags, 'title'));
        $magazine->setSummary($this->getTagValue($tags, 'summary'));
        $magazine->setImage($this->getTagValue($tags, 'image'));
        $magazine->setLanguage($this->getTagValue($tags, 'l'));
        $magazine->setTags($this->getAllTagValues($tags, 't'));
        $magazine->setPubkey($magazineEvent->getPubkey());
        $magazine->setPublishedAt(\DateTimeImmutable::createFromFormat('U', (string)$magazineEvent->getCreatedAt()));
        $magazine->touch();

        // Extract category coordinates and process each category
        $categoryCoordinates = $this->extractCategoryCoordinates($tags);
        $categories = [];
        $allContributors = [];
        $allRelays = [];
        $allKinds = [];

        foreach ($categoryCoordinates as $coordinate) {
            $categoryData = $this->processCategory($coordinate);

            if ($categoryData) {
                $categories[] = [
                    'slug' => $categoryData['slug'],
                    'title' => $categoryData['title'],
                    'summary' => $categoryData['summary'],
                    'articleCount' => count($categoryData['articles']),
                ];

                // Collect contributors, relays, and kinds
                $allContributors = array_merge($allContributors, $categoryData['contributors']);
                $allRelays = array_merge($allRelays, $categoryData['relays']);
                $allKinds = array_merge($allKinds, $categoryData['kinds']);
            }
        }

        $magazine->setCategories($categories);
        $magazine->setContributors(array_values(array_unique($allContributors)));
        $magazine->setRelayPool(array_values(array_unique($allRelays)));
        $magazine->setContainedKinds(array_values(array_unique($allKinds)));

        // Persist the magazine
        $this->magazineRepository->save($magazine);

        $this->logger->info('Magazine projection completed', [
            'slug' => $slug,
            'categories' => count($categories),
            'contributors' => count($magazine->getContributors()),
            'relays' => count($magazine->getRelayPool()),
        ]);

        return $magazine;
    }

    /**
     * Process a category event and extract article data
     *
     * @param string $coordinate Category coordinate (kind:pubkey:slug)
     * @return array|null Category data or null if not found
     */
    private function processCategory(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            $this->logger->warning('Invalid coordinate format', ['coordinate' => $coordinate]);
            return null;
        }

        [$kind, $pubkey, $slug] = $parts;

        if ((int)$kind !== 30040) {
            $this->logger->warning('Coordinate is not kind 30040', ['coordinate' => $coordinate]);
            return null;
        }

        $categoryEvent = $this->findEventBySlugAndPubkey($slug, $pubkey);

        if (!$categoryEvent) {
            $this->logger->warning('Category event not found, skipping gracefully', [
                'coordinate' => $coordinate,
                'slug' => $slug,
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
            return null;
        }

        $tags = $categoryEvent->getTags();

        // Extract article coordinates
        $articleCoordinates = $this->extractArticleCoordinates($tags);

        // Process articles to get contributors, relays, and kinds
        $contributors = [];
        $relays = [];
        $kinds = [];

        foreach ($articleCoordinates as $articleCoord) {
            $articleData = $this->processArticle($articleCoord);
            if ($articleData) {
                $contributors[] = $articleData['pubkey'];
                $kinds[] = $articleData['kind'];
                // For now, we'll skip relay extraction as it requires fetching relay lists
                // This can be enhanced later to fetch kind 10002 events
            }
        }

        return [
            'slug' => $slug,
            'title' => $this->getTagValue($tags, 'title') ?? '',
            'summary' => $this->getTagValue($tags, 'summary') ?? '',
            'articles' => $articleCoordinates,
            'contributors' => array_unique($contributors),
            'relays' => array_unique($relays),
            'kinds' => array_unique($kinds),
        ];
    }

    /**
     * Process an article coordinate to extract metadata
     *
     * @param string $coordinate Article coordinate (kind:pubkey:slug)
     * @return array|null Article data or null if not found
     */
    private function processArticle(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            $this->logger->warning('Invalid article coordinate', ['coordinate' => $coordinate]);
            return null;
        }

        [$kind, $pubkey, $slug] = $parts;

        // We don't need to fetch the full article, just extract the metadata
        return [
            'kind' => (int)$kind,
            'pubkey' => $pubkey,
            'slug' => $slug,
        ];
    }

    /**
     * Find an event by slug (d tag)
     */
    private function findEventBySlug(string $slug): ?Event
    {
        $repo = $this->em->getRepository(Event::class);

        // Query for kind 30040 events with matching d tag
        $qb = $repo->createQueryBuilder('e');
        $qb->where('e.kind = :kind')
            ->setParameter('kind', 30040)
            ->orderBy('e.created_at', 'DESC');

        $events = $qb->getQuery()->getResult();

        // Filter by d tag (we need to do this in PHP since JSON querying differs by DB)
        foreach ($events as $event) {
            $tags = $event->getTags();
            foreach ($tags as $tag) {
                if (isset($tag[0], $tag[1]) && $tag[0] === 'd' && $tag[1] === $slug) {
                    return $event;
                }
            }
        }

        return null;
    }

    /**
     * Find an event by slug and pubkey
     */
    private function findEventBySlugAndPubkey(string $slug, string $pubkey): ?Event
    {
        $repo = $this->em->getRepository(Event::class);

        $qb = $repo->createQueryBuilder('e');
        $qb->where('e.kind = :kind')
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kind', 30040)
            ->setParameter('pubkey', $pubkey)
            ->orderBy('e.created_at', 'DESC');

        $events = $qb->getQuery()->getResult();

        // Filter by d tag
        foreach ($events as $event) {
            $tags = $event->getTags();
            foreach ($tags as $tag) {
                if (isset($tag[0], $tag[1]) && $tag[0] === 'd' && $tag[1] === $slug) {
                    return $event;
                }
            }
        }

        return null;
    }

    /**
     * Extract category coordinates (30040:pubkey:slug) from tags
     *
     * @param array $tags Event tags
     * @return array<string>
     */
    private function extractCategoryCoordinates(array $tags): array
    {
        $coordinates = [];
        foreach ($tags as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === 'a' && str_starts_with((string)$tag[1], '30040:')) {
                $coordinates[] = (string)$tag[1];
            }
        }
        return $coordinates;
    }

    /**
     * Extract article coordinates from tags (excluding 30040 categories)
     *
     * @param array $tags Event tags
     * @return array<string>
     */
    private function extractArticleCoordinates(array $tags): array
    {
        $coordinates = [];
        foreach ($tags as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === 'a' && !str_starts_with((string)$tag[1], '30040:')) {
                $coordinates[] = (string)$tag[1];
            }
        }
        return $coordinates;
    }

    /**
     * Get a single tag value by name
     */
    private function getTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === $name) {
                return (string)$tag[1];
            }
        }
        return null;
    }

    /**
     * Get all tag values by name
     *
     * @return array<string>
     */
    private function getAllTagValues(array $tags, string $name): array
    {
        $values = [];
        foreach ($tags as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === $name) {
                $values[] = (string)$tag[1];
            }
        }
        return $values;
    }
}
