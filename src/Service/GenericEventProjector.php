<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\Graph\RecordIdentityService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;

/**
 * Projects generic Nostr events into Event entities
 * Used for event kinds that don't have specialized projectors
 *
 * Handles replaceable event semantics (NIP-01):
 *   - kind 0, 3, 10000–19999: only the latest event per pubkey+kind is kept
 *   - kind 30000–39999: only the latest event per pubkey+kind+d_tag is kept
 */
class GenericEventProjector
{
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly UserRolePromoter $userRolePromoter,
        private readonly RecordIdentityService $recordIdentityService,
        private readonly ReplaceableEventCleanupService $cleanupService,
    ) {
    }

    /**
     * Returns a live (non-closed) EntityManager.
     * After a failed flush the injected EM is stale/closed;
     * always go through the registry so we get the current instance.
     */
    private function em(): ObjectManager
    {
        $em = $this->managerRegistry->getManagerForClass(Event::class);
        if ($em === null || ($em instanceof EntityManagerInterface && !$em->isOpen())) {
            $em = $this->managerRegistry->resetManager();
        }
        return $em;
    }

    /**
     * Project a generic event from a Nostr event object
     *
     * @param object $event The Nostr event object
     * @param string $relayUrl The relay URL where the event was received
     * @return Event The persisted Event entity
     * @throws \InvalidArgumentException If event is invalid
     * @throws \Exception|\Throwable If database operation fails
     */
    public function projectEventFromNostrEvent(object $event, string $relayUrl): Event
    {
        // Validate event has required fields
        if (!isset($event->id) || !isset($event->kind)) {
            throw new \InvalidArgumentException('Invalid event: missing required fields (id, kind)');
        }

        // Check if event already exists
        $existing = $this->eventRepository->find($event->id);
        if ($existing) {
            $this->logger->debug('Event already exists in database', [
                'event_id' => $event->id,
                'kind' => $event->kind,
                'relay' => $relayUrl
            ]);
            return $existing;
        }

        // For replaceable events, skip if a newer version already exists.
        // This prevents re-inserting stale events that arrive out of order.
        $kind = (int) ($event->kind ?? 0);
        $pubkey = $event->pubkey ?? '';
        if ($pubkey !== '' && $this->isReplaceableOrParameterized($kind)) {
            $newerExists = $this->newerReplaceableExists($kind, $pubkey, $event);
            if ($newerExists) {
                $this->logger->debug('Skipping older replaceable event — newer version exists', [
                    'event_id' => $event->id,
                    'kind' => $kind,
                    'pubkey' => substr($pubkey, 0, 16) . '...',
                ]);
                return $newerExists;
            }
        }

        // Create new Event entity
        $entity = new Event();
        $entity->setId($event->id);
        $entity->setKind($event->kind ?? 0);
        $entity->setPubkey($event->pubkey ?? '');
        $entity->setContent($event->content ?? '');
        $entity->setCreatedAt($event->created_at ?? 0);
        $entity->setTags($event->tags ?? []);
        $entity->setSig($event->sig ?? '');
        $entity->extractAndSetDTag();

        // Persist to database
        $em = $this->em();
        try {
            $em->persist($entity);
            $em->flush();
        } catch (\Throwable $e) {
            // Reset entity manager so subsequent events can still be processed
            $this->managerRegistry->resetManager();

            // Duplicate key (SQLSTATE 23505) = another worker inserted the same
            // event between our find() check and flush(). This is expected with
            // concurrent subscription workers; treat it as "already exists".
            if (str_contains($e->getMessage(), '23505') || str_contains($e->getMessage(), 'duplicate key')) {
                $this->logger->debug('Event inserted by concurrent worker, skipping', [
                    'event_id' => $event->id,
                    'kind' => $event->kind,
                ]);
                return $this->eventRepository->find($event->id) ?? $entity;
            }

            throw $e;
        }

        // Update graph layer tables (parsed_reference + current_record)
        try {
            $this->eventIngestionListener->processEvent($entity);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update graph tables for event', [
                'event_id' => $event->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Clean up older versions of replaceable events (NIP-01).
        // For kind 0, 3, 10000–19999: only keep latest per pubkey+kind.
        // For kind 30000–39999: only keep latest per pubkey+kind+d_tag.
        $em = $this->em();
        if ($em instanceof \Doctrine\ORM\EntityManagerInterface) {
            $this->cleanupService->removeOlderEventVersions($entity, $em);
        }

        $this->logger->info('Generic event saved to database', [
            'event_id' => $event->id,
            'kind' => $event->kind,
            'pubkey' => substr($event->pubkey ?? '', 0, 16) . '...',
            'relay' => $relayUrl
        ]);

        // Grant ROLE_EDITOR to authors of reading lists / magazines (kind 30040)
        if ((int) $event->kind === KindsEnum::PUBLICATION_INDEX->value && !empty($event->pubkey)) {
            try {
                $this->userRolePromoter->promoteToEditor($event->pubkey);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to promote event author to ROLE_EDITOR', [
                    'pubkey' => substr($event->pubkey, 0, 16) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $entity;
    }

    /**
     * Check if a kind is replaceable or parameterized replaceable (NIP-01).
     */
    private function isReplaceableOrParameterized(int $kind): bool
    {
        return $this->recordIdentityService->isReplaceable($kind)
            || $this->recordIdentityService->isParameterizedReplaceable($kind);
    }

    /**
     * Return the existing DB event if it is newer than the incoming one.
     * Returns null when the incoming event should be stored.
     */
    private function newerReplaceableExists(int $kind, string $pubkey, object $incoming): ?Event
    {
        $currentEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, $kind);
        if ($currentEvent === null) {
            return null;
        }

        // For parameterized replaceable, d_tag must also match
        if ($this->recordIdentityService->isParameterizedReplaceable($kind)) {
            $incomingDTag = $this->extractDTag($incoming);
            if (($currentEvent->getDTag() ?? '') !== ($incomingDTag ?? '')) {
                return null; // different d_tag — not the same logical record
            }
        }

        $incomingCreatedAt = (int) ($incoming->created_at ?? 0);
        $existingCreatedAt = $currentEvent->getCreatedAt();

        // Existing is strictly newer → skip incoming
        if ($existingCreatedAt > $incomingCreatedAt) {
            return $currentEvent;
        }

        // Same timestamp → NIP-01 tie-break: lower event id wins
        if ($existingCreatedAt === $incomingCreatedAt
            && $currentEvent->getId() < $incoming->id) {
            return $currentEvent;
        }

        return null; // incoming is newer (or wins tie-break) → proceed
    }

    /**
     * Extract the d tag value from a raw event object.
     */
    private function extractDTag(object $event): ?string
    {
        $tags = $event->tags ?? [];
        if (!is_array($tags)) {
            return null;
        }
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === 'd' && array_key_exists(1, $tag)) {
                return (string) $tag[1];
            }
        }
        return null;
    }


    /**
     * Get statistics about events by kind
     *
     * @param array<int> $kinds Optional array of kinds to filter by
     * @return array Statistics array
     */
    public function getEventStats(array $kinds = []): array
    {
        $em = $this->em();
        $qb = $em->createQueryBuilder();
        $qb->select('e.kind', 'COUNT(e.id) as count')
            ->from(Event::class, 'e')
            ->groupBy('e.kind')
            ->orderBy('e.kind', 'ASC');

        if (!empty($kinds)) {
            $qb->where($qb->expr()->in('e.kind', ':kinds'))
                ->setParameter('kinds', $kinds);
        }

        $results = $qb->getQuery()->getResult();

        $stats = [
            'total' => 0,
            'by_kind' => []
        ];

        foreach ($results as $result) {
            $kind = (int)$result['kind'];
            $count = (int)$result['count'];
            $stats['by_kind'][$kind] = $count;
            $stats['total'] += $count;
        }

        return $stats;
    }

    /**
     * Get count of events for a specific kind
     *
     * @param int $kind The event kind
     * @return int Event count
     */
    public function getEventCountByKind(int $kind): int
    {
        return $this->eventRepository->count(['kind' => $kind]);
    }
}
