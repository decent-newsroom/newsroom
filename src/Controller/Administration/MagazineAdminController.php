<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Nzine;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Redis as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

class MagazineAdminController extends AbstractController
{
    #[Route('/admin/magazines', name: 'admin_magazines')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(RedisClient $redis, CacheInterface $redisCache, EntityManagerInterface $em): Response
    {
        // Optimized database-first approach
        $magazines = $this->getMagazinesFromDatabase($em, $redis, $redisCache);

        return $this->render('admin/magazines.html.twig', [
            'magazines' => $magazines,
        ]);
    }

    #[Route('/admin/magazines/{npub}/delete', name: 'admin_magazine_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $npub, EntityManagerInterface $em): Response
    {
        try {
            // Find and delete all events associated with this nzine (main index + category indices)
            $events = $em->getRepository(Event::class)->findBy([
                'pubkey' => $npub,
                'kind' => KindsEnum::PUBLICATION_INDEX->value
            ]);

            $deletedCount = count($events);

            foreach ($events as $event) {
                $em->remove($event);
            }

            // Also delete the Nzine entity itself
            $nzine = $em->getRepository(\App\Entity\Nzine::class)->findOneBy(['npub' => $npub]);
            if ($nzine) {
                $em->remove($nzine);
            }

            $em->flush();

            $this->addFlash('success', sprintf(
                'Deleted nzine and %d associated index events.',
                $deletedCount
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to delete nzine: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_magazines');
    }

    #[Route('/admin/magazines/orphaned', name: 'admin_magazines_orphaned')]
    #[IsGranted('ROLE_ADMIN')]
    public function listOrphaned(EntityManagerInterface $em): Response
    {

        // Find nzines (entities)
        $nzines = $em->getRepository(\App\Entity\Nzine::class)->findAll();
        $nzineNpubs = array_map(fn($n) => $n->getNpub(), $nzines);

        // Also find malformed nzines (have Nzine entity but no or broken indices)
        $malformed = [];
        foreach ($nzines as $nzine) {
            $npub = $nzine->getNpub();
            $hasIndices = isset($indexesByPubkey[$npub]) && !empty($indexesByPubkey[$npub]);

            if (!$hasIndices || empty($nzine->getSlug())) {
                $malformed[] = [
                    'nzine' => $nzine,
                    'npub' => $npub,
                    'slug' => $nzine->getSlug(),
                    'state' => $nzine->getState(),
                    'categories' => count($nzine->getMainCategories()),
                    'indices' => $indexesByPubkey[$npub] ?? [],
                ];
            }
        }

        return $this->render('admin/magazines_orphaned.html.twig', [
            'malformed' => $nzines,
        ]);
    }

    private function getMagazinesFromDatabase(EntityManagerInterface $em, RedisClient $redis, CacheInterface $redisCache): array
    {
        // 1) Get magazine events directly from database using indexed queries
        $magazineEvents = $this->getMagazineEvents($em);

        // 2) Get all category events in one query
        $categoryCoordinates = $this->extractCategoryCoordinates($magazineEvents);
        $categoryEvents = $this->getCategoryEventsByCoordinates($em, $categoryCoordinates);

        // 3) Get all article slugs and batch query articles
        $articleCoordinates = $this->extractArticleCoordinates($categoryEvents);
        $articles = $this->getArticlesByCoordinates($em, $articleCoordinates);

        // 4) Build magazine structure efficiently
        return $this->buildMagazineStructure($magazineEvents, $categoryEvents, $articles);
    }

    private function getMagazineEvents(EntityManagerInterface $em): array
    {
        // Query magazines using database index on kind
        $qb = $em->createQueryBuilder();
        $qb->select('e')
           ->from(Event::class, 'e')
           ->where('e.kind = :magazineKind')
           ->setParameter('magazineKind', KindsEnum::PUBLICATION_INDEX->value)
           ->orderBy('e.created_at', 'DESC');

        $allMagazineEvents = $qb->getQuery()->getResult();

        // Filter to only show top-level magazines (those that contain other indexes, not direct articles)
        return $this->filterTopLevelMagazines($allMagazineEvents);
    }

    private function filterTopLevelMagazines(array $magazineEvents): array
    {
        $topLevelMagazines = [];
        // Sort by createdAt descending to keep latest in case of duplicates
        usort($magazineEvents, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        $seenSlugs = [];
        foreach ($magazineEvents as $event) {
            $hasSubIndexes = false;
            $hasDirectArticles = false;
            $isOldDuplicate = false;
            foreach ($event->getTags() as $tag) {
                // Keep a list of slugs, so you can skip if already there
                if ($tag[0] === 'd' && isset($tag[1])) {
                    if (in_array($tag[1], $seenSlugs)) {
                        $isOldDuplicate = true;
                    }
                    $seenSlugs[] = $tag[1];
                }
                if ($tag[0] === 'a' && isset($tag[1])) {
                    $coord = $tag[1];

                    // Check if this coordinate points to another index (30040:...)
                    if (str_starts_with($coord, '30040:')) {
                        $hasSubIndexes = true;
                    }
                    // Check if this coordinate points to articles (30023: or 30024:)
                    elseif (str_starts_with($coord, '30023:') || str_starts_with($coord, '30024:')) {
                        $hasDirectArticles = true;
                    }
                }
            }

            // Only include magazines that have sub-indexes but no direct articles
            // This identifies top-level magazines that organize other indexes
            if ($hasSubIndexes && !$hasDirectArticles && !$isOldDuplicate) {
                $topLevelMagazines[] = $event;
            }
        }
        return $topLevelMagazines;
    }

    private function extractCategoryCoordinates(array $magazineEvents): array
    {
        $coordinates = [];
        foreach ($magazineEvents as $event) {
            foreach ($event->getTags() as $tag) {
                if ($tag[0] === 'a' && isset($tag[1]) && str_starts_with($tag[1], '30040:')) {
                    $coordinates[] = $tag[1];
                }
            }
        }
        return array_unique($coordinates);
    }

    private function getCategoryEventsByCoordinates(EntityManagerInterface $em, array $coordinates): array
    {
        if (empty($coordinates)) {
            return [];
        }

        // Extract slugs from coordinates for efficient querying
        $slugs = [];
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) === 3) {
                $slugs[] = $parts[2];
            }
        }

        if (empty($slugs)) {
            return [];
        }

        // Use PostgreSQL-compatible JSON operations
        $connection = $em->getConnection();
        $placeholders = str_repeat('?,', count($slugs) - 1) . '?';

        $sql = "
            SELECT * FROM event e
            WHERE e.kind = ?
            AND EXISTS (
                SELECT 1 FROM json_array_elements(e.tags) AS tag_element
                WHERE tag_element->>0 = 'd'
                AND tag_element->>1 IN ({$placeholders})
            )
        ";

        $params = [KindsEnum::PUBLICATION_INDEX->value, ...$slugs];
        $result = $connection->executeQuery($sql, $params);
        $rows = $result->fetchAllAssociative();

        // Convert result rows back to Event entities
        $indexed = [];
        foreach ($rows as $row) {
            $event = new Event();
            $event->setId($row['id']);
            if ($row['event_id'] !== null) {
                $event->setEventId($row['event_id']);
            }
            $event->setKind($row['kind']);
            $event->setPubkey($row['pubkey']);
            $event->setContent($row['content']);
            $event->setCreatedAt($row['created_at']);
            $event->setTags(json_decode($row['tags'], true) ?: []);
            $event->setSig($row['sig']);

            $slug = $event->getSlug();
            if ($slug) {
                $indexed[$slug] = $event;
            }
        }

        return $indexed;
    }

    private function extractArticleCoordinates(array $categoryEvents): array
    {
        $coordinates = [];
        foreach ($categoryEvents as $event) {
            foreach ($event->getTags() as $tag) {
                if ($tag[0] === 'a' && isset($tag[1])) {
                    $coordinates[] = $tag[1];
                }
            }
        }
        return array_unique($coordinates);
    }

    private function getArticlesByCoordinates(EntityManagerInterface $em, array $coordinates): array
    {
        if (empty($coordinates)) {
            return [];
        }

        // Extract slugs for batch query
        $slugs = [];
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) === 3 && !empty($parts[2])) {
                $slugs[] = $parts[2];
            }
        }

        if (empty($slugs)) {
            return [];
        }

        // Batch query articles using index on slug
        $qb = $em->createQueryBuilder();
        $qb->select('a')
           ->from(Article::class, 'a')
           ->where($qb->expr()->in('a.slug', ':slugs'))
           ->setParameter('slugs', $slugs);

        $articles = $qb->getQuery()->getResult();

        // Index by slug for efficient lookup
        $indexed = [];
        foreach ($articles as $article) {
            if ($article->getSlug()) {
                $indexed[$article->getSlug()] = $article;
            }
        }

        return $indexed;
    }

    private function buildMagazineStructure(array $magazineEvents, array $categoryEvents, array $articles): array
    {
        $magazines = [];

        foreach ($magazineEvents as $event) {
            $magazineData = $this->parseEventTags($event);

            // Build categories for this magazine
            $categories = [];
            foreach ($magazineData['a'] as $coord) {
                if (!str_starts_with($coord, '30040:')) continue;

                $parts = explode(':', $coord, 3);
                if (count($parts) !== 3) continue;

                $catSlug = $parts[2];
                if (!isset($categoryEvents[$catSlug])) continue;

                $categoryEvent = $categoryEvents[$catSlug];
                $categoryData = $this->parseEventTags($categoryEvent);

                // Build files for this category
                $files = [];
                foreach ($categoryData['a'] as $aCoord) {
                    $partsA = explode(':', $aCoord, 3);
                    if (count($partsA) !== 3) continue;

                    $artSlug = $partsA[2];
                    $authorPubkey = $partsA[1] ?? '';

                    /** @var Article $article */
                    $article = $articles[$artSlug] ?? null;
                    $title = $article ? $article->getTitle() : $artSlug;

                    // Get the date - prefer publishedAt, fallback to createdAt
                    $date = null;
                    if ($article) {
                        $date = $article->getPublishedAt() ?? $article->getCreatedAt();
                    }

                    $files[] = [
                        'name' => $title ?? $artSlug,
                        'slug' => $artSlug,
                        'coordinate' => $aCoord,
                        'authorPubkey' => $authorPubkey,
                        'date' => $date,
                    ];
                }

                $categories[] = [
                    'name' => $categoryData['title'],
                    'slug' => $categoryData['slug'],
                    'files' => $files,
                ];
            }

            $magazines[] = [
                'name' => $magazineData['title'],
                'slug' => $magazineData['slug'],
                'categories' => $categories,
            ];
        }

        return $magazines;
    }

    private function parseEventTags($event): array
    {
        $title = null; $slug = null; $a = [];
        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0])) continue;
            if ($tag[0] === 'title' && isset($tag[1])) $title = $tag[1];
            if ($tag[0] === 'd' && isset($tag[1])) $slug = $tag[1];
            if ($tag[0] === 'a' && isset($tag[1])) $a[] = $tag[1];
        }
        return [
            'title' => $title ?? ($slug ?? '(untitled)'),
            'slug' => $slug ?? '',
            'a' => $a,
        ];
    }

    private function getFallbackMagazinesFromRedis(RedisClient $redis, CacheInterface $redisCache, EntityManagerInterface $em): array
    {
        // Fallback to original Redis implementation for backward compatibility
        $slugs = [];
        try {
            $members = $redis->sMembers('magazine_slugs');
            if (is_array($members)) {
                $slugs = array_values(array_unique(array_filter($members)));
            }
        } catch (\Throwable) {
            // ignore set errors
        }

        // Check for main magazine in database instead of Redis
        try {
            $conn = $em->getConnection();
            $sql = "SELECT e.* FROM event e
                    WHERE e.tags::jsonb @> ?::jsonb
                    LIMIT 1";
            $result = $conn->executeQuery($sql, [
                json_encode([['d', 'newsroom-magazine-by-newsroom']])
            ]);
            $mainEventData = $result->fetchAssociative();

            if ($mainEventData !== false && !in_array('newsroom-magazine-by-newsroom', $slugs, true)) {
                $slugs[] = 'newsroom-magazine-by-newsroom';
            }
        } catch (\Throwable) {
            // ignore
        }

        $magazines = [];
        $parse = function(array $tags): array {
            $title = null; $slug = null; $a = [];
            foreach ($tags as $tag) {
                if (!is_array($tag) || !isset($tag[0])) continue;
                if ($tag[0] === 'title' && isset($tag[1])) $title = $tag[1];
                if ($tag[0] === 'd' && isset($tag[1])) $slug = $tag[1];
                if ($tag[0] === 'a' && isset($tag[1])) $a[] = $tag[1];
            }
            return [
                'title' => $title ?? ($slug ?? '(untitled)'),
                'slug' => $slug ?? '',
                'a' => $a,
            ];
        };

        $conn = $em->getConnection();
        $sql = "SELECT e.* FROM event e
                WHERE e.tags::jsonb @> ?::jsonb
                LIMIT 1";

        foreach ($slugs as $slug) {
            // Query database for magazine event
            try {
                $result = $conn->executeQuery($sql, [
                    json_encode([['d', $slug]])
                ]);
                $eventData = $result->fetchAssociative();

                if ($eventData === false) continue;

                $tags = json_decode($eventData['tags'], true);
            } catch (\Throwable) {
                continue;
            }

            $data = $parse($tags);
            $categories = [];

            foreach ($data['a'] as $coord) {
                if (!str_starts_with((string)$coord, '30040:')) continue;
                $parts = explode(':', (string)$coord, 3);
                if (count($parts) !== 3) continue;

                $catSlug = $parts[2];

                // Query database for category event
                try {
                    $catResult = $conn->executeQuery($sql, [
                        json_encode([['d', $catSlug]])
                    ]);
                    $catEventData = $catResult->fetchAssociative();

                    if ($catEventData === false) continue;

                    $catTags = json_decode($catEventData['tags'], true);
                } catch (\Throwable) {
                    continue;
                }

                $catData = $parse($catTags);
                $files = [];
                $repo = $em->getRepository(Article::class);

                foreach ($catData['a'] as $aCoord) {
                    $partsA = explode(':', (string)$aCoord, 3);
                    if (count($partsA) !== 3) continue;

                    $artSlug = $partsA[2];
                    $authorPubkey = $partsA[1] ?? '';
                    $title = null;
                    $date = null;

                    if ($artSlug !== '') {
                        $article = $repo->findOneBy(['slug' => $artSlug]);
                        if ($article) {
                            $title = $article->getTitle();
                            // Get the date - prefer publishedAt, fallback to createdAt
                            $date = $article->getPublishedAt() ?? $article->getCreatedAt();
                        }
                    }

                    $files[] = [
                        'name' => $title ?? $artSlug,
                        'slug' => $artSlug,
                        'coordinate' => $aCoord,
                        'authorPubkey' => $authorPubkey,
                        'date' => $date,
                    ];
                }

                $categories[] = [
                    'name' => $catData['title'],
                    'slug' => $catData['slug'],
                    'files' => $files,
                ];
            }

            $magazines[] = [
                'name' => $data['title'],
                'slug' => $data['slug'],
                'categories' => $categories,
            ];
        }

        return $magazines;
    }
}
