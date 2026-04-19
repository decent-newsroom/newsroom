<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\Event;
use App\Service\Graph\RecordIdentityService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cleans up older versions of replaceable and parameterized-replaceable events (NIP-01).
 *
 * Can be called from any ingestion path after a new event is persisted.
 */
class ReplaceableEventCleanupService
{
    public function __construct(
        private readonly RecordIdentityService $recordIdentityService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Remove older versions of a replaceable/parameterized-replaceable Event entity.
     * Must be called AFTER the new event is already flushed to the database.
     */
    public function removeOlderEventVersions(Event $newEvent, EntityManagerInterface $em): int
    {
        $kind = $newEvent->getKind();
        $pubkey = $newEvent->getPubkey();

        if (!$this->recordIdentityService->isReplaceable($kind)
            && !$this->recordIdentityService->isParameterizedReplaceable($kind)) {
            return 0;
        }

        try {
            $qb = $em->createQueryBuilder();

            $qb->delete(Event::class, 'e')
                ->where('e.pubkey = :pubkey')
                ->andWhere('e.kind = :kind')
                ->andWhere('e.id != :currentId')
                ->andWhere(
                    $qb->expr()->orX(
                        'e.created_at < :createdAt',
                        $qb->expr()->andX(
                            'e.created_at = :createdAt',
                            'e.id > :currentId'   // NIP-01 tie-break: lower id wins
                        )
                    )
                )
                ->setParameter('pubkey', $pubkey)
                ->setParameter('kind', $kind)
                ->setParameter('currentId', $newEvent->getId())
                ->setParameter('createdAt', $newEvent->getCreatedAt());

            if ($this->recordIdentityService->isParameterizedReplaceable($kind)) {
                $qb->andWhere('e.dTag = :dTag')
                    ->setParameter('dTag', $newEvent->getDTag() ?? '');
            }

            $deleted = $qb->getQuery()->execute();

            if ($deleted > 0) {
                $this->logger->debug('Cleaned up older replaceable event versions', [
                    'kind' => $kind,
                    'pubkey' => substr($pubkey, 0, 16) . '...',
                    'd_tag' => $newEvent->getDTag(),
                    'deleted_count' => $deleted,
                    'kept_event_id' => $newEvent->getId(),
                ]);
            }

            return (int) $deleted;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to clean up older replaceable event versions', [
                'kind' => $kind,
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Remove older Article revisions sharing the same pubkey + kind + slug (d-tag).
     *
     * NIP-01: parameterized-replaceable events are keyed by (pubkey, kind, d-tag).
     * Kinds 30023 (longform) and 30024 (draft) share a d-tag but are DIFFERENT
     * addresses and MUST NOT replace each other — so we scope the cleanup to the
     * same kind as the new article.
     *
     * Tie-break follows NIP-01: on equal created_at, the lexicographically lower
     * event id wins; all others are removed.
     *
     * Must be called AFTER the new article is flushed.
     */
    public function removeOlderArticleRevisions(Article $newArticle, EntityManagerInterface $em): int
    {
        $pubkey = $newArticle->getPubkey();
        $slug = $newArticle->getSlug();
        $kind = $newArticle->getKind();

        if (!$pubkey || !$slug || !$kind) {
            return 0;
        }

        try {
            $qb = $em->createQueryBuilder();

            $qb->delete(Article::class, 'a')
                ->where('a.pubkey = :pubkey')
                ->andWhere('a.kind = :kind')
                ->andWhere('a.slug = :slug')
                ->andWhere('a.id != :currentId')
                ->andWhere(
                    $qb->expr()->orX(
                        'a.createdAt < :createdAt',
                        $qb->expr()->andX(
                            'a.createdAt = :createdAt',
                            // NIP-01 tie-break: lower Nostr event id wins.
                            'a.eventId > :currentEventId'
                        )
                    )
                )
                ->setParameter('pubkey', $pubkey)
                ->setParameter('kind', $kind)
                ->setParameter('slug', $slug)
                ->setParameter('currentId', $newArticle->getId())
                ->setParameter('currentEventId', $newArticle->getEventId() ?? '')
                ->setParameter('createdAt', $newArticle->getCreatedAt());

            $deleted = $qb->getQuery()->execute();

            if ($deleted > 0) {
                $this->logger->debug('Cleaned up older article revisions', [
                    'pubkey' => substr($pubkey, 0, 16) . '...',
                    'kind' => $kind instanceof \BackedEnum ? $kind->value : $kind,
                    'slug' => $slug,
                    'deleted_count' => $deleted,
                    'kept_id' => $newArticle->getId(),
                ]);
            }

            return (int) $deleted;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to clean up older article revisions', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}

