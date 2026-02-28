<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use Psr\Cache\CacheItemPoolInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Finds reading lists / curation sets containing a given article
 * and resolves the previous and next articles in that list.
 */
class ReadingListNavigationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * Given an article coordinate (e.g. "30023:pubkey:slug"), find the
     * first reading list (kind 30040) or curation set (kind 30004)
     * that contains it, and return prev/next Article objects plus list metadata.
     *
     * @return array{
     *     listTitle: ?string,
     *     listSlug: ?string,
     *     listPubkey: ?string,
     *     listKind: ?int,
     *     prevArticle: ?Article,
     *     nextArticle: ?Article
     * }|null  Returns null if no list contains this article.
     */
    public function findNavigation(string $articleCoordinate): ?array
    {
        $cacheKey = 'reading_list_nav_' . md5($articleCoordinate);

        try {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                /** @var array|null $cached */
                $cached = $item->get();
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache failures should never break the request
        }

        // Use PostgreSQL JSON containment to find events whose tags contain an 'a' tag referencing this article
        $sql = "SELECT e.* FROM event e
                WHERE e.tags::jsonb @> ?::jsonb
                AND e.kind IN (30040, 30004)
                ORDER BY e.created_at DESC";

        try {
            $conn = $this->em->getConnection();
            $result = $conn->executeQuery($sql, [
                json_encode([['a', $articleCoordinate]])
            ]);

            $rows = $result->fetchAllAssociative();
        } catch (\Exception $e) {
            $this->logger->warning('ReadingListNavigationService: query failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (empty($rows)) {
            return null;
        }

        $resultValue = null;

        // Try each list until we find one with a resolvable prev/next
        foreach ($rows as $row) {
            $tags = json_decode($row['tags'], true);
            if (!is_array($tags)) {
                continue;
            }

            // Gather list metadata
            $listTitle = null;
            $listSlug = null;
            $coordinates = [];

            foreach ($tags as $tag) {
                if (!is_array($tag)) continue;
                if (($tag[0] ?? null) === 'title' && isset($tag[1])) {
                    $listTitle = $tag[1];
                }
                if (($tag[0] ?? null) === 'd' && isset($tag[1])) {
                    $listSlug = $tag[1];
                }
                if (($tag[0] ?? null) === 'a' && isset($tag[1])) {
                    $coordinates[] = $tag[1];
                }
            }

            // Skip magazine index events (those that reference other 30040 events)
            $isMagazineIndex = false;
            foreach ($coordinates as $coord) {
                if (str_starts_with($coord, '30040:')) {
                    $isMagazineIndex = true;
                    break;
                }
            }
            if ($isMagazineIndex) {
                continue;
            }

            // Find position of the article in the list
            $currentIndex = array_search($articleCoordinate, $coordinates);
            if ($currentIndex === false) {
                continue;
            }

            $prevCoord = $currentIndex > 0 ? $coordinates[$currentIndex - 1] : null;
            $nextCoord = $currentIndex < count($coordinates) - 1 ? $coordinates[$currentIndex + 1] : null;

            if ($prevCoord === null && $nextCoord === null) {
                continue; // Only one item in list, no navigation needed
            }

            $prevArticle = $prevCoord ? $this->resolveArticle($prevCoord) : null;
            $nextArticle = $nextCoord ? $this->resolveArticle($nextCoord) : null;

            // Only return if we can resolve at least one neighbor
            if ($prevArticle === null && $nextArticle === null) {
                continue;
            }

            $resultValue = [
                'listTitle' => $listTitle,
                'listSlug' => $listSlug,
                'listPubkey' => $row['pubkey'],
                'listKind' => (int) $row['kind'],
                'prevArticle' => $prevArticle,
                'nextArticle' => $nextArticle,
            ];

            break;
        }

        // Cache result (including null) briefly to protect DB under load.
        try {
            $item = $this->cache->getItem($cacheKey);
            $item->set($resultValue);
            $item->expiresAfter(300); // 5 minutes
            $this->cache->save($item);
        } catch (\Throwable $e) {
        }

        return $resultValue;
    }

    /**
     * Resolve a coordinate like "30023:pubkey:slug" to an Article entity.
     */
    private function resolveArticle(string $coordinate): ?Article
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$kind, $pubkey, $slug] = $parts;

        $repo = $this->em->getRepository(Article::class);
        $article = $repo->findOneBy(
            ['slug' => $slug, 'pubkey' => $pubkey],
            ['createdAt' => 'DESC']
        );

        return $article;
    }
}

