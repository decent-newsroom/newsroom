<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Article;
use App\Entity\DeletedEvent;
use App\Entity\Event;
use App\Entity\Highlight;
use App\Entity\Magazine;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\MagazineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes NIP-09 (kind 5) event deletion requests.
 *
 * For each `e` tag: look up the referenced event by id, verify that its
 * pubkey matches the deletion request author (MUST per spec §Client Usage),
 * then remove it from all local stores. For each `a` tag: parse the
 * `kind:pubkey:d` coordinate, verify pubkey match, and remove every version
 * whose `created_at` is <= the deletion request's `created_at`.
 *
 * A tombstone row (DeletedEvent) is persisted per honored reference so that
 * late-arriving re-publishes are suppressed by {@see EventRepository}/
 * {@see GenericEventProjector} at ingestion time.
 *
 * Deletion of a deletion request (kind 5 targeting another kind 5) is a no-op,
 * per NIP-09 §"Deletion Request of a Deletion Request".
 */
class EventDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $eventRepository,
        private readonly MagazineRepository $magazineRepository,
        private readonly DeletedEventRepository $deletedEventRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process a kind:5 deletion request that has already been persisted.
     *
     * @return array{processed:int, suppressed:int, skipped:int}
     */
    public function processDeletionRequest(Event $deletionRequest): array
    {
        if ($deletionRequest->getKind() !== KindsEnum::DELETION_REQUEST->value) {
            throw new \InvalidArgumentException('Event is not a kind 5 deletion request');
        }

        $requesterPubkey = $deletionRequest->getPubkey();
        $requestedAt = $deletionRequest->getCreatedAt();
        $reason = $deletionRequest->getContent() ?: null;

        // Collect kinds from k-tags (hint for the kind of each referenced target).
        $kHints = [];
        foreach ($deletionRequest->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === 'k' && isset($tag[1]) && ctype_digit((string) $tag[1])) {
                $kHints[] = (int) $tag[1];
            }
        }

        $processed = 0;
        $suppressed = 0;
        $skipped = 0;

        foreach ($deletionRequest->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1])) {
                continue;
            }

            try {
                if ($tag[0] === 'e') {
                    $result = $this->handleEventIdReference(
                        (string) $tag[1],
                        $requesterPubkey,
                        $deletionRequest->getId(),
                        $requestedAt,
                        $reason,
                        $kHints,
                    );
                } elseif ($tag[0] === 'a') {
                    $result = $this->handleCoordinateReference(
                        (string) $tag[1],
                        $requesterPubkey,
                        $deletionRequest->getId(),
                        $requestedAt,
                        $reason,
                    );
                } else {
                    continue;
                }

                if ($result === 'processed') { $processed++; }
                elseif ($result === 'suppressed') { $suppressed++; }
                else { $skipped++; }
            } catch (\Throwable $e) {
                $this->logger->warning('NIP-09: failed to process deletion target', [
                    'deletion_event' => $deletionRequest->getId(),
                    'tag' => $tag,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->em->flush();

        $this->logger->info('NIP-09: deletion request processed', [
            'deletion_event' => $deletionRequest->getId(),
            'pubkey' => substr($requesterPubkey, 0, 16) . '...',
            'processed' => $processed,
            'suppressed' => $suppressed,
            'skipped' => $skipped,
        ]);

        return ['processed' => $processed, 'suppressed' => $suppressed, 'skipped' => $skipped];
    }

    /**
     * Handle an `e` tag (reference to a concrete event id).
     *
     * @param int[] $kHints
     * @return 'processed'|'suppressed'|'skipped'
     */
    private function handleEventIdReference(
        string $eventId,
        string $requesterPubkey,
        string $deletionEventId,
        int $requestedAt,
        ?string $reason,
        array $kHints,
    ): string {
        $target = $this->eventRepository->find($eventId);

        // NIP-09: never act on a kind 5 target
        if ($target !== null && $target->getKind() === KindsEnum::DELETION_REQUEST->value) {
            return 'skipped';
        }

        // Spec MUST: pubkey of target == pubkey of deletion request
        if ($target !== null && $target->getPubkey() !== $requesterPubkey) {
            $this->logger->info('NIP-09: ignoring e-tag with mismatched pubkey', [
                'event_id' => $eventId,
                'target_pubkey' => substr($target->getPubkey(), 0, 16) . '...',
                'requester' => substr($requesterPubkey, 0, 16) . '...',
            ]);
            return 'skipped';
        }

        $kind = $target?->getKind() ?? ($kHints[0] ?? null);

        // Always record the tombstone (even when the target is not locally known
        // yet) so a future re-ingest is suppressed.
        $this->recordTombstone(
            targetRef: $eventId,
            refType: DeletedEvent::REF_EVENT_ID,
            pubkey: $requesterPubkey,
            kind: $kind,
            deletionEventId: $deletionEventId,
            deletionCreatedAt: $requestedAt,
            reason: $reason,
        );

        if ($target === null) {
            return 'suppressed'; // tombstone recorded, nothing locally to remove
        }

        $this->cascadeDelete($target->getKind(), $requesterPubkey, $target->getDTag(), $eventId, null);

        return 'processed';
    }

    /**
     * Handle an `a` tag (reference to a replaceable coordinate kind:pubkey:d).
     *
     * @return 'processed'|'suppressed'|'skipped'
     */
    private function handleCoordinateReference(
        string $coordinate,
        string $requesterPubkey,
        string $deletionEventId,
        int $requestedAt,
        ?string $reason,
    ): string {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0])) {
            return 'skipped';
        }

        [$kindStr, $targetPubkey, $dTag] = $parts;
        $kind = (int) $kindStr;

        if ($kind === KindsEnum::DELETION_REQUEST->value) {
            return 'skipped';
        }
        if ($targetPubkey !== $requesterPubkey) {
            $this->logger->info('NIP-09: ignoring a-tag with mismatched pubkey', [
                'coordinate' => $coordinate,
                'requester' => substr($requesterPubkey, 0, 16) . '...',
            ]);
            return 'skipped';
        }

        $this->recordTombstone(
            targetRef: $coordinate,
            refType: DeletedEvent::REF_COORDINATE,
            pubkey: $requesterPubkey,
            kind: $kind,
            deletionEventId: $deletionEventId,
            deletionCreatedAt: $requestedAt,
            reason: $reason,
        );

        $this->cascadeDelete($kind, $requesterPubkey, $dTag, null, $requestedAt);

        return 'processed';
    }

    /**
     * Remove the referenced artifacts from all local stores.
     *
     * @param string|null $specificEventId when set, only remove this exact event id
     * @param int|null    $upToCreatedAt   when set (a-tag path), remove all versions with created_at <= this
     */
    private function cascadeDelete(
        int $kind,
        string $pubkey,
        ?string $dTag,
        ?string $specificEventId,
        ?int $upToCreatedAt,
    ): void {
        // 1) Remove Event rows (the generic Nostr event table)
        $qb = $this->em->createQueryBuilder()
            ->delete(Event::class, 'e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind = :kind')
            ->setParameter('pubkey', $pubkey)
            ->setParameter('kind', $kind);
        if ($specificEventId !== null) {
            $qb->andWhere('e.id = :id')->setParameter('id', $specificEventId);
        } elseif ($dTag !== null) {
            $qb->andWhere('e.dTag = :dTag')->setParameter('dTag', $dTag);
        }
        if ($upToCreatedAt !== null) {
            $qb->andWhere('e.created_at <= :upTo')->setParameter('upTo', $upToCreatedAt);
        }
        $removedEvents = (int) $qb->getQuery()->execute();

        // 2) Domain-specific cascades by kind
        $removedDomain = 0;
        switch ($kind) {
            case KindsEnum::LONGFORM->value:
            case KindsEnum::LONGFORM_DRAFT->value:
                $removedDomain = $this->deleteArticles($pubkey, $dTag, $specificEventId, $upToCreatedAt);
                break;

            case KindsEnum::HIGHLIGHTS->value:
                $removedDomain = $this->deleteHighlights($pubkey, $specificEventId, $upToCreatedAt);
                break;

            case KindsEnum::PUBLICATION_INDEX->value:
            case KindsEnum::PUBLICATION_CONTENT->value:
                $removedDomain = $this->deleteMagazines($pubkey, $dTag);
                break;
        }

        $this->logger->info('NIP-09: cascaded local deletion', [
            'kind' => $kind,
            'pubkey' => substr($pubkey, 0, 16) . '...',
            'd_tag' => $dTag,
            'event_id' => $specificEventId,
            'removed_events' => $removedEvents,
            'removed_domain' => $removedDomain,
        ]);
    }

    private function deleteArticles(string $pubkey, ?string $dTag, ?string $eventId, ?int $upToCreatedAt): int
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(Article::class, 'a')
            ->where('a.pubkey = :pubkey')
            ->setParameter('pubkey', $pubkey);

        if ($eventId !== null) {
            $qb->andWhere('a.eventId = :eventId')->setParameter('eventId', $eventId);
        } elseif ($dTag !== null) {
            $qb->andWhere('a.slug = :slug')->setParameter('slug', $dTag);
        }
        if ($upToCreatedAt !== null) {
            $qb->andWhere('a.createdAt <= :upTo')
               ->setParameter('upTo', (new \DateTimeImmutable('@' . $upToCreatedAt)));
        }

        return (int) $qb->getQuery()->execute();
    }

    private function deleteHighlights(string $pubkey, ?string $eventId, ?int $upToCreatedAt): int
    {
        $qb = $this->em->createQueryBuilder()
            ->delete(Highlight::class, 'h')
            ->where('h.pubkey = :pubkey')
            ->setParameter('pubkey', $pubkey);

        if ($eventId !== null) {
            $qb->andWhere('h.eventId = :eventId')->setParameter('eventId', $eventId);
        }
        if ($upToCreatedAt !== null) {
            $qb->andWhere('h.createdAt <= :upTo')->setParameter('upTo', $upToCreatedAt);
        }

        return (int) $qb->getQuery()->execute();
    }

    private function deleteMagazines(string $pubkey, ?string $dTag): int
    {
        if ($dTag === null) {
            return 0;
        }
        // Magazine slug equals the d-tag of the kind:30040 index event.
        $magazine = $this->magazineRepository->findBySlug($dTag);
        if ($magazine === null || $magazine->getPubkey() !== $pubkey) {
            return 0;
        }
        $this->em->remove($magazine);
        return 1;
    }

    private function recordTombstone(
        string $targetRef,
        string $refType,
        string $pubkey,
        ?int $kind,
        string $deletionEventId,
        int $deletionCreatedAt,
        ?string $reason,
    ): void {
        $existing = $this->deletedEventRepository->findByTargetRef($targetRef);
        if ($existing !== null) {
            // Keep the latest deletion_created_at so we honor a wider window.
            if ($deletionCreatedAt > $existing->getDeletionCreatedAt()) {
                $existing->setDeletionEventId($deletionEventId);
                $existing->setDeletionCreatedAt($deletionCreatedAt);
                if ($reason !== null) { $existing->setReason($reason); }
                if ($kind !== null) { $existing->setKind($kind); }
            }
            return;
        }

        $tombstone = new DeletedEvent();
        $tombstone->setTargetRef($targetRef);
        $tombstone->setRefType($refType);
        $tombstone->setPubkey($pubkey);
        $tombstone->setKind($kind);
        $tombstone->setDeletionEventId($deletionEventId);
        $tombstone->setDeletionCreatedAt($deletionCreatedAt);
        $tombstone->setReason($reason);
        $this->em->persist($tombstone);
    }
}




