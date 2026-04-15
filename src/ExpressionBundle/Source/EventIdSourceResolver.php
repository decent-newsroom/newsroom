<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\Entity\Event;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelayRegistry;
use Psr\Log\LoggerInterface;

/**
 * Resolves event ID references: DB-first, relay fallback.
 */
final class EventIdSourceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly NostrRequestExecutor $requestExecutor,
        private readonly RelayRegistry $relayRegistry,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $eventId, RuntimeContext $ctx): array
    {
        // DB-first
        $event = $this->eventRepository->findById($eventId);
        if ($event !== null) {
            $this->logger->debug('Event resolved from DB', ['eventId' => $eventId]);
            return [new NormalizedItem($event)];
        }

        // Relay fallback
        $this->logger->debug('Event not in DB, fetching from relays', ['eventId' => $eventId]);
        try {
            $relaySet = null; // use default
            $rawEvents = $this->requestExecutor->fetch(
                kinds: [],
                filters: ['ids' => [$eventId], 'limit' => 1],
            );

            foreach ($rawEvents as $raw) {
                $event = $this->convertToEntity($raw);
                if ($event !== null) {
                    $this->logger->debug('Event resolved from relays', ['eventId' => $eventId]);
                    return [new NormalizedItem($event)];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Event relay fetch failed', [
                'eventId' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }

        throw new UnresolvedRefException("Event not found: {$eventId}");
    }

    private function convertToEntity(object $raw): ?Event
    {
        if (!isset($raw->id, $raw->pubkey, $raw->kind)) {
            return null;
        }
        $event = new Event();
        $event->setId($raw->id);
        $event->setPubkey($raw->pubkey);
        $event->setKind((int) $raw->kind);
        $event->setContent($raw->content ?? '');
        $event->setCreatedAt((int) ($raw->created_at ?? 0));
        $event->setTags((array) ($raw->tags ?? []));
        $event->setSig($raw->sig ?? '');
        return $event;
    }
}
