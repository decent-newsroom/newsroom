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
 * Resolves pubkey-list container events (kind:3 contacts and kind:39089 follow packs)
 * into synthetic items keyed by each contained p-tag pubkey.
 */
final class PubkeyListSourceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        $listEvent = $this->resolveEventByAddress($address);

        return $this->expandPubkeys($listEvent, $address);
    }

    /** @return NormalizedItem[] */
    public function executeEvent(Event $listEvent, RuntimeContext $ctx): array
    {
        $label = $listEvent->getId() ?: 'unknown';
        $this->logger->debug('Expanding pubkey list from pre-resolved event', ['eventId' => $label]);

        return $this->expandPubkeys($listEvent, $label);
    }

    /**
     * Resolve a pubkey-list address into unique pubkeys.
     *
     * @return string[]
     */
    public function resolvePubkeysByAddress(string $address): array
    {
        $listEvent = $this->resolveEventByAddress($address);

        return $this->extractPubkeysFromEvent($listEvent);
    }

    /**
     * Extract unique pubkeys from a pubkey-list event.
     *
     * @return string[]
     */
    public function extractPubkeysFromEvent(Event $listEvent): array
    {
        $pubkeys = [];
        foreach ($listEvent->getTags() as $tag) {
            if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                $pubkeys[] = $tag[1];
            }
        }

        return array_values(array_unique($pubkeys));
    }

    private function resolveEventByAddress(string $address): Event
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $kind = (int) $kind;

        $this->logger->debug('Resolving pubkey list by address', ['address' => $address, 'kind' => $kind]);

        $listEvent = $kind === 3
            ? $this->eventRepository->findLatestByPubkeyAndKind($pubkey, 3)
            : $this->eventRepository->findByNaddr($kind, $pubkey, $d);

        if ($listEvent === null) {
            throw new UnresolvedRefException("Pubkey list not found: {$address}");
        }

        return $listEvent;
    }

    /** @return NormalizedItem[] */
    private function expandPubkeys(Event $listEvent, string $label): array
    {
        $pubkeys = $this->extractPubkeysFromEvent($listEvent);

        $items = [];
        foreach ($pubkeys as $pubkey) {
            $event = new Event();
            $event->setId('pubkey-list:' . $label . ':' . $pubkey);
            $event->setKind(3);
            $event->setPubkey($pubkey);
            $event->setCreatedAt($listEvent->getCreatedAt());
            $event->setContent('');
            $event->setTags([]);
            $event->setSig('');

            $items[] = new NormalizedItem($event);
        }

        $this->logger->debug('Expanded pubkey list', [
            'label' => $label,
            'pubkeys' => count($items),
        ]);

        return $items;
    }
}


