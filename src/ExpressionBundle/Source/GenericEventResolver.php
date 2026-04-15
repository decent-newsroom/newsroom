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
 * Generic resolver for address references that don't match a specialized resolver.
 * Simply fetches the event by naddr and returns it as a single-item list.
 */
final class GenericEventResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);

        $this->logger->debug('Resolving generic event', ['address' => $address, 'kind' => (int) $kind]);

        $event = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            throw new UnresolvedRefException("Event not found: {$address}");
        }
        return [new NormalizedItem($event)];
    }
}
