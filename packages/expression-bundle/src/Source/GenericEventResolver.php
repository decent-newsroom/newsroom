<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Generic resolver for address references that don't match a specialized resolver.
 * Simply fetches the event by naddr and returns it as a single-item list.
 */
final class GenericEventResolver
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);

        $this->logger->debug('Resolving generic event', ['address' => $address, 'kind' => (int) $kind]);

        $event = $this->eventStore->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            throw new UnresolvedRefException("Event not found: {$address}");
        }
        return [new NormalizedItem($event)];
    }
}
