<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use DecentNewsroom\ExpressionBundle\Model\ArrayEvent;
use Psr\Log\LoggerInterface;

/**
 * Resolves pubkey-list container events (kind:3 contacts and kind:39089 follow packs)
 * into synthetic items keyed by each contained p-tag pubkey.
 */
final class PubkeyListSourceResolver
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        $listEvent = $this->resolveEventByAddress($address);

        return $this->expandPubkeys($listEvent, $address);
    }

    /** @return NormalizedItem[] */
    public function executeEvent(EventInterface $listEvent, RuntimeContext $ctx): array
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
    public function extractPubkeysFromEvent(EventInterface $listEvent): array
    {
        $pubkeys = [];
        foreach ($listEvent->getTags() as $tag) {
            if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                $pubkeys[] = $tag[1];
            }
        }

        return array_values(array_unique($pubkeys));
    }

    private function resolveEventByAddress(string $address): EventInterface
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $kind = (int) $kind;

        $this->logger->debug('Resolving pubkey list by address', ['address' => $address, 'kind' => $kind]);

        $listEvent = $kind === 3
            ? $this->eventStore->findLatestByPubkeyAndKind($pubkey, 3)
            : $this->eventStore->findByNaddr($kind, $pubkey, $d);

        if ($listEvent === null) {
            throw new UnresolvedRefException("Pubkey list not found: {$address}");
        }

        return $listEvent;
    }

    /** @return NormalizedItem[] */
    private function expandPubkeys(EventInterface $listEvent, string $label): array
    {
        $pubkeys = $this->extractPubkeysFromEvent($listEvent);

        $items = [];
        foreach ($pubkeys as $pubkey) {
            $event = new ArrayEvent(
                id: 'pubkey-list:' . $label . ':' . $pubkey,
                kind: 3,
                pubkey: $pubkey,
                content: '',
                createdAt: $listEvent->getCreatedAt(),
                tags: [],
            );

            $items[] = new NormalizedItem($event);
        }

        $this->logger->debug('Expanded pubkey list', [
            'label' => $label,
            'pubkeys' => count($items),
        ]);

        return $items;
    }
}
