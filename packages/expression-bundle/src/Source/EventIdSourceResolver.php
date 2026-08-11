<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;
use Psr\Log\LoggerInterface;

/**
 * Resolves event ID references through the generic local-store/relay resolver.
 *
 * For the relay fallback, the resolver queries a broad union of relays:
 * the local relay, the configured content relays, and — as an additional
 * probe — the requesting user's NIP-65 read relays. The viewer is NOT
 * assumed to be the author of the input event: expressions and the
 * spells they reference can be authored by anyone and evaluated by
 * anyone, so we cannot rely on the viewer's relays to carry an arbitrary
 * referenced event. The viewer's relays are included at a lower priority
 * purely as an extra probe; the canonical fetch paths are the local
 * relay and the configured content relays.
 */
final class EventIdSourceResolver
{
    public function __construct(
        private readonly EventResolver $eventResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $eventId, RuntimeContext $ctx): array
    {
        $event = $this->eventResolver->findById($eventId, $ctx);
        if ($event !== null) {
            $this->logger->debug('Event resolved from local store or relays', ['eventId' => $eventId]);
            return [new NormalizedItem($event)];
        }

        if ($event !== null) {
            $this->logger->debug('Event resolved from local store or relays', ['eventId' => $eventId]);
            return [new NormalizedItem($event)];
        }

        throw new UnresolvedRefException("Event not found: {$eventId}");
    }
}
