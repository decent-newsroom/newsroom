<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ArticleInPublication;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleInPublication>
 */
class ArticleInPublicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleInPublication::class);
    }

    /**
     * All index rows for a given article coordinate.
     *
     * @return ArticleInPublication[]
     */
    public function findByArticleCoordinate(string $coordinate): array
    {
        return $this->findBy(['articleCoordinate' => $coordinate]);
    }

    /**
     * Rebuild the index for one container (kind 30040 event identified by pubkey+dTag).
     *
     * - Removes every row that previously pointed to this container.
     * - Inserts a fresh row for every supplied article coordinate.
     *
     * The method runs inside a single transaction so a concurrent reader
     * never sees a half-rebuilt state.
     *
     * @param string   $containerPubkey   Pubkey of the kind 30040 event
     * @param string   $containerDTag     d-tag of the kind 30040 event
     * @param string[] $articleCoordinates New set of article coordinates
     * @param string|null $containerTitle  'title' tag value (cached for display)
     */
    public function upsertForContainer(
        string $containerPubkey,
        string $containerDTag,
        array $articleCoordinates,
        ?string $containerTitle,
    ): void {
        $conn = $this->getEntityManager()->getConnection();

        $conn->beginTransaction();
        try {
            // Remove stale rows for this container
            $conn->executeStatement(
                'DELETE FROM article_in_publication WHERE container_pubkey = :pub AND container_d_tag = :dtag',
                ['pub' => $containerPubkey, 'dtag' => $containerDTag],
            );

            // Insert fresh rows
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($articleCoordinates as $articleCoord) {
                $conn->executeStatement(
                    'INSERT INTO article_in_publication
                        (article_coordinate, container_pubkey, container_d_tag, container_title, updated_at)
                     VALUES (:ac, :pub, :dtag, :title, :now)
                     ON CONFLICT (article_coordinate, container_pubkey, container_d_tag)
                         DO UPDATE SET container_title = EXCLUDED.container_title,
                                       updated_at      = EXCLUDED.updated_at',
                    [
                        'ac'    => $articleCoord,
                        'pub'   => $containerPubkey,
                        'dtag'  => $containerDTag,
                        'title' => $containerTitle,
                        'now'   => $now,
                    ],
                );
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Remove all index entries that reference a specific container.
     * Called when a kind 30040 event is superseded or deleted.
     */
    public function removeForContainer(string $containerPubkey, string $containerDTag): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM article_in_publication WHERE container_pubkey = :pub AND container_d_tag = :dtag',
            ['pub' => $containerPubkey, 'dtag' => $containerDTag],
        );
    }
}

