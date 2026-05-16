<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleInPublicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Maintains the article_in_publication index and provides reverse-lookup:
 * given an article coordinate, which publications (magazines / reading lists) include it?
 *
 * Index lifecycle
 * ───────────────
 * • Called by GenericEventProjector whenever a kind 30040 event is persisted.
 * • Can be rebuilt in bulk via the `app:rebuild-publication-index` command.
 *
 * Query-time resolution
 * ─────────────────────
 * Fast path:  article_in_publication index table (O(1) lookup by article coordinate).
 * Fallback:   direct JSONB scan of the event table when the index has no entry.
 *             The fallback is logged so operators know the index needs rebuilding.
 */
class ArticlePublicationIndexer
{
    public function __construct(
        private readonly ArticleInPublicationRepository $repository,
        private readonly EntityManagerInterface         $em,
        private readonly LoggerInterface                $logger,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Index maintenance
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Process a kind 30040 event and refresh the index for that container.
     *
     * Idempotent: calling it multiple times with the same event is safe.
     */
    public function indexPublicationEvent(Event $event): void
    {
        if ($event->getKind() !== KindsEnum::PUBLICATION_INDEX->value) {
            return;
        }

        $containerPubkey = $event->getPubkey();
        $containerDTag   = $event->getDTag() ?? $event->getSlug();

        if ($containerDTag === null || $containerDTag === '') {
            $this->logger->debug('ArticlePublicationIndexer: skipping event without d-tag', [
                'event_id' => $event->getId(),
            ]);
            return;
        }

        // Collect article coordinates referenced by this event
        $articleCoordinates = [];
        foreach ($event->getTags() as $tag) {
            if (!isset($tag[0], $tag[1]) || $tag[0] !== 'a') {
                continue;
            }
            $coord = (string) $tag[1];
            // Only direct article references (not sub-index 30040 pointers)
            if (str_starts_with($coord, '30023:') || str_starts_with($coord, '30024:')) {
                $articleCoordinates[] = $coord;
            }
        }

        try {
            $this->repository->upsertForContainer(
                $containerPubkey,
                $containerDTag,
                $articleCoordinates,
                $event->getTitle(),
            );

            $this->logger->debug('ArticlePublicationIndexer: indexed container', [
                'pubkey'   => substr($containerPubkey, 0, 8) . '...',
                'd_tag'    => $containerDTag,
                'articles' => count($articleCoordinates),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ArticlePublicationIndexer: failed to upsert for container', [
                'pubkey' => substr($containerPubkey, 0, 8) . '...',
                'd_tag'  => $containerDTag,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Query
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Return all publications that contain the given article.
     *
     * Tries the fast AIP index first; falls back to a direct JSONB scan when
     * the index has no entry (e.g. before the first rebuild, or for articles
     * ingested before the feature was deployed).
     *
     * Each entry in the returned array:
     * [
     *   'magazine_title'    => string|null,
     *   'magazine_slug'     => string,
     *   'magazine_pubkey'   => string,
     *   'category_title'    => string|null,   // null when article is directly in the magazine
     *   'category_slug'     => string|null,
     *   'is_reading_list'   => bool,          // true when the top-level container has type='reading-list'
     * ]
     *
     * @return array<int, array{magazine_title: string|null, magazine_slug: string, magazine_pubkey: string, category_title: string|null, category_slug: string|null, is_reading_list: bool}>
     */
    public function findPublicationsForArticle(string $articlePubkey, string $articleSlug): array
    {
        // Build both possible coordinates: published (30023) and draft (30024)
        $coordinate30023 = "30023:{$articlePubkey}:{$articleSlug}";
        $coordinate30024 = "30024:{$articlePubkey}:{$articleSlug}";

        // ── Fast path: AIP index ──────────────────────────────────────────
        $rows = array_merge(
            $this->repository->findByArticleCoordinate($coordinate30023),
            $this->repository->findByArticleCoordinate($coordinate30024),
        );

        if (!empty($rows)) {
            return $this->resolveFromAipRows($rows);
        }

        // ── Fallback: direct JSONB scan ───────────────────────────────────
        $this->logger->info('ArticlePublicationIndexer: AIP miss, using direct query', [
            'coordinate' => $coordinate30023,
        ]);

        return $this->findPublicationsDirect($articlePubkey, $articleSlug);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve AIP rows into the publication display structure.
     *
     * @param  \App\Entity\ArticleInPublication[] $rows
     * @return array<int, array{magazine_title: string|null, magazine_slug: string, magazine_pubkey: string, category_title: string|null, category_slug: string|null, is_reading_list: bool}>
     */
    private function resolveFromAipRows(array $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            $containerCoord = "30040:{$row->getContainerPubkey()}:{$row->getContainerDTag()}";
            $parentData     = $this->findParentMagazineData($containerCoord);

            if ($parentData !== null) {
                $results[] = [
                    'magazine_title'  => $parentData['title'],
                    'magazine_slug'   => $parentData['slug'],
                    'magazine_pubkey' => $parentData['pubkey'],
                    'category_title'  => $row->getContainerTitle(),
                    'category_slug'   => $row->getContainerDTag(),
                    'is_reading_list' => false,
                ];
            } else {
                $containerType = $this->getContainerTypeTag($row->getContainerPubkey(), $row->getContainerDTag());
                $results[] = [
                    'magazine_title'  => $row->getContainerTitle(),
                    'magazine_slug'   => $row->getContainerDTag(),
                    'magazine_pubkey' => $row->getContainerPubkey(),
                    'category_title'  => null,
                    'category_slug'   => null,
                    'is_reading_list' => $containerType === 'reading-list',
                ];
            }
        }

        return $this->deduplicateByMagazine($results);
    }

    /**
     * Direct JSONB query – used when the AIP index has no entry for this article.
     * Finds every kind 30040 event whose 'a' tags reference this article's coordinate,
     * then resolves the parent magazine for each.
     *
     * @return array<int, array{magazine_title: string|null, magazine_slug: string, magazine_pubkey: string, category_title: string|null, category_slug: string|null, is_reading_list: bool}>
     */
    private function findPublicationsDirect(string $articlePubkey, string $articleSlug): array
    {
        $conn = $this->em->getConnection();

        // Look for kind 30040 events that directly include this article (either kind).
        $sql = "
            SELECT e.pubkey, e.tags
            FROM   event e
            WHERE  e.kind = :kind
              AND  (
                       EXISTS (
                           SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                           WHERE  tag->>0 = 'a'
                             AND  tag->>1 = :coord30023
                       )
                   OR
                       EXISTS (
                           SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                           WHERE  tag->>0 = 'a'
                             AND  tag->>1 = :coord30024
                       )
                   )
            ORDER BY e.created_at DESC
        ";

        $containerRows = $conn->executeQuery($sql, [
            'kind'       => KindsEnum::PUBLICATION_INDEX->value,
            'coord30023' => "30023:{$articlePubkey}:{$articleSlug}",
            'coord30024' => "30024:{$articlePubkey}:{$articleSlug}",
        ])->fetchAllAssociative();

        if (empty($containerRows)) {
            return [];
        }

        $results = [];

        foreach ($containerRows as $containerRow) {
            $tags = is_string($containerRow['tags'])
                ? (json_decode($containerRow['tags'], true) ?? [])
                : $containerRow['tags'];

            $containerTitle  = null;
            $containerDTag   = null;
            $containerType   = null;
            foreach ($tags as $tag) {
                if (($tag[0] ?? '') === 'title' && isset($tag[1])) {
                    $containerTitle = (string) $tag[1];
                }
                if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                    $containerDTag = (string) $tag[1];
                }
                if (($tag[0] ?? '') === 'type' && isset($tag[1])) {
                    $containerType = (string) $tag[1];
                }
            }

            if ($containerDTag === null) {
                continue;
            }

            $containerCoord = "30040:{$containerRow['pubkey']}:{$containerDTag}";
            $parentData     = $this->findParentMagazineData($containerCoord);

            if ($parentData !== null) {
                $results[] = [
                    'magazine_title'  => $parentData['title'],
                    'magazine_slug'   => $parentData['slug'],
                    'magazine_pubkey' => $parentData['pubkey'],
                    'category_title'  => $containerTitle,
                    'category_slug'   => $containerDTag,
                    'is_reading_list' => false,
                ];
            } else {
                $results[] = [
                    'magazine_title'  => $containerTitle,
                    'magazine_slug'   => $containerDTag,
                    'magazine_pubkey' => $containerRow['pubkey'],
                    'category_title'  => null,
                    'category_slug'   => null,
                    'is_reading_list' => $containerType === 'reading-list',
                ];
            }
        }

        return $this->deduplicateByMagazine($results);
    }

    /**
     * Find the kind 30040 event that references $categoryCoordinate in an 'a' tag.
     *
     * Returns a minimal array [title, slug, pubkey] or null when no parent found.
     *
     * @return array{title: string|null, slug: string, pubkey: string}|null
     */
    private function findParentMagazineData(string $categoryCoordinate): ?array
    {
        $conn = $this->em->getConnection();

        $sql = "SELECT e.pubkey, e.tags
                FROM event e
                WHERE e.kind = :kind
                  AND EXISTS (
                      SELECT 1
                      FROM   jsonb_array_elements(e.tags) AS tag
                      WHERE  tag->>0 = 'a'
                        AND  tag->>1 = :coord
                  )
                ORDER BY e.created_at DESC
                LIMIT 1";

        /** @var array{pubkey: string, tags: string|array<mixed>}|false $row */
        $row = $conn->executeQuery($sql, [
            'kind'  => KindsEnum::PUBLICATION_INDEX->value,
            'coord' => $categoryCoordinate,
        ])->fetchAssociative();

        if (!$row) {
            return null;
        }

        $tags = is_string($row['tags']) ? (json_decode($row['tags'], true) ?? []) : $row['tags'];

        $title = null;
        $slug  = '';
        foreach ($tags as $tag) {
            if (($tag[0] ?? '') === 'title' && isset($tag[1])) {
                $title = (string) $tag[1];
            }
            if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                $slug = (string) $tag[1];
            }
        }

        if ($slug === '') {
            return null;
        }

        return [
            'title'  => $title,
            'slug'   => $slug,
            'pubkey' => $row['pubkey'],
        ];
    }

    /**
     * Fetch the 'type' tag value from a kind 30040 container event identified by pubkey + d-tag.
     * Returns null when the event is not found or has no 'type' tag.
     */
    private function getContainerTypeTag(string $pubkey, string $dtag): ?string
    {
        $conn = $this->em->getConnection();

        $sql = "SELECT e.tags
                FROM   event e
                WHERE  e.kind    = :kind
                  AND  e.pubkey  = :pubkey
                  AND  EXISTS (
                      SELECT 1
                      FROM   jsonb_array_elements(e.tags) AS tag
                      WHERE  tag->>0 = 'd'
                        AND  tag->>1 = :dtag
                  )
                ORDER BY e.created_at DESC
                LIMIT 1";

        /** @var array{tags: string|array<mixed>}|false $row */
        $row = $conn->executeQuery($sql, [
            'kind'   => KindsEnum::PUBLICATION_INDEX->value,
            'pubkey' => $pubkey,
            'dtag'   => $dtag,
        ])->fetchAssociative();

        if (!$row) {
            return null;
        }

        $tags = is_string($row['tags']) ? (json_decode($row['tags'], true) ?? []) : $row['tags'];

        foreach ($tags as $tag) {
            if (($tag[0] ?? '') === 'type' && isset($tag[1])) {
                return (string) $tag[1];
            }
        }

        return null;
    }

    /**
     * Deduplicate a results list by magazine (slug + pubkey).
     *
     * @param  array<int, array{magazine_title: string|null, magazine_slug: string, magazine_pubkey: string, category_title: string|null, category_slug: string|null, is_reading_list: bool}> $results
     * @return array<int, array{magazine_title: string|null, magazine_slug: string, magazine_pubkey: string, category_title: string|null, category_slug: string|null, is_reading_list: bool}>
     */
    private function deduplicateByMagazine(array $results): array
    {
        $seen    = [];
        $deduped = [];
        foreach ($results as $item) {
            $key = $item['magazine_slug'] . ':' . $item['magazine_pubkey'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[]  = $item;
            }
        }
        return $deduped;
    }
}

