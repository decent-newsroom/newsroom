<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ArticleInPublication;
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
 * A row in article_in_publication points to the kind 30040 event that DIRECTLY
 * lists the article.  That event is typically a "category" inside a top-level
 * magazine.  findPublicationsForArticle() performs a second-level look-up to
 * find the parent magazine (if any) so the sidebar can show
 * "Magazine → Category" links.
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
     * Each entry in the returned array describes one publication context:
     * [
     *   'magazine_title'    => string|null,
     *   'magazine_slug'     => string,
     *   'magazine_pubkey'   => string,
     *   'category_title'    => string|null,   // null when article is directly in the magazine
     *   'category_slug'     => string|null,
     * ]
     *
     * @return array<int, array{magazine_title: string|null, magazine_slug: string, magazine_pubkey: string, category_title: string|null, category_slug: string|null}>
     */
    public function findPublicationsForArticle(string $articlePubkey, string $articleSlug): array
    {
        $coordinate = "30023:{$articlePubkey}:{$articleSlug}";
        $rows       = $this->repository->findByArticleCoordinate($coordinate);

        if (empty($rows)) {
            return [];
        }

        $results = [];

        foreach ($rows as $row) {
            $containerCoord = "30040:{$row->getContainerPubkey()}:{$row->getContainerDTag()}";

            // Look up the parent magazine: a kind 30040 event that has an 'a' tag
            // pointing to this container coordinate.
            $parentData = $this->findParentMagazineData($containerCoord);

            if ($parentData !== null) {
                // Container is a category inside a larger magazine
                $results[] = [
                    'magazine_title'  => $parentData['title'],
                    'magazine_slug'   => $parentData['slug'],
                    'magazine_pubkey' => $parentData['pubkey'],
                    'category_title'  => $row->getContainerTitle(),
                    'category_slug'   => $row->getContainerDTag(),
                ];
            } else {
                // Container IS the top-level magazine (article included directly)
                $results[] = [
                    'magazine_title'  => $row->getContainerTitle(),
                    'magazine_slug'   => $row->getContainerDTag(),
                    'magazine_pubkey' => $row->getContainerPubkey(),
                    'category_title'  => null,
                    'category_slug'   => null,
                ];
            }
        }

        // Deduplicate by magazine_slug (an article may appear in multiple categories
        // of the same magazine; show the magazine only once in that case).
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

    // ──────────────────────────────────────────────────────────────────────
    // Internals
    // ──────────────────────────────────────────────────────────────────────

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
            'kind' => KindsEnum::PUBLICATION_INDEX->value,
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
}

