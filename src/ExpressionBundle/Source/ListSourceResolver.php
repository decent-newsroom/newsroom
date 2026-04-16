<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\Entity\Event;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;

/**
 * Resolves NIP-51 list references into their contained events.
 */
final class ListSourceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $kind = (int) $kind;

        $this->logger->debug('Resolving NIP-51 list by address', ['address' => $address, 'kind' => $kind]);

        // Find the list event
        $listEvent = $this->eventRepository->findByNaddr($kind, $pubkey, $d);
        if ($listEvent === null) {
            // For kind 10003 (bookmarks), use pubkey + kind lookup
            $listEvent = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, $kind);
        }
        if ($listEvent === null) {
            throw new UnresolvedRefException("List not found: {$address}");
        }

        return $this->expandList($listEvent, $address);
    }

    /**
     * Expand a list from an already-resolved Event (skips DB lookup).
     *
     * @return NormalizedItem[]
     */
    public function executeEvent(Event $listEvent, RuntimeContext $ctx): array
    {
        $label = $listEvent->getId() ?: 'unknown';
        $this->logger->debug('Expanding list from pre-resolved event', ['eventId' => $label]);

        return $this->expandList($listEvent, $label);
    }

    /** @return NormalizedItem[] */
    private function expandList(Event $listEvent, string $label): array
    {
        $eventIds = [];
        $addresses = [];
        foreach ($listEvent->getTags() as $tag) {
            if (($tag[0] ?? '') === 'e' && isset($tag[1])) {
                $eventIds[] = $tag[1];
            } elseif (($tag[0] ?? '') === 'a' && isset($tag[1])) {
                $addresses[] = $tag[1];
            }
        }

        $this->logger->debug('List references extracted', [
            'label' => $label,
            'eventIds' => count($eventIds),
            'addresses' => count($addresses),
        ]);

        $items = [];

        // Resolve event IDs
        if (!empty($eventIds)) {
            $events = $this->eventRepository->findByIds($eventIds);
            foreach ($events as $event) {
                $items[] = new NormalizedItem($event);
            }
        }

        // Resolve addresses
        foreach ($addresses as $addr) {
            $parts = explode(':', $addr, 3);
            if (count($parts) === 3) {
                $event = $this->eventRepository->findByNaddr((int) $parts[0], $parts[1], $parts[2]);
                if ($event !== null) {
                    $items[] = new NormalizedItem($event);
                }
            }
        }

        $this->logger->info('List resolved', [
            'label' => $label,
            'resolvedItems' => count($items),
            'totalRefs' => count($eventIds) + count($addresses),
        ]);

        return $items;
    }
}
