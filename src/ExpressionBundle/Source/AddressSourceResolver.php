<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\Entity\Event;
use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use Psr\Log\LoggerInterface;

/**
 * Dispatches address references (a-tag) by kind to specialized resolvers.
 */
final class AddressSourceResolver
{
    private const LIST_KINDS = [30003, 30004, 30005, 30006, 10003];

    public function __construct(
        private readonly ExpressionSourceResolver $expressionResolver,
        private readonly SpellSourceResolver $spellResolver,
        private readonly ListSourceResolver $listResolver,
        private readonly GenericEventResolver $genericEventResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        $parts = explode(':', $address, 3);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Invalid address format: {$address}");
        }

        $kind = (int) $parts[0];
        $resolverType = $this->resolverTypeForKind($kind);

        $this->logger->debug('Dispatching address reference', [
            'address' => $address,
            'kind' => $kind,
            'resolver' => $resolverType,
        ]);

        return match ($resolverType) {
            'expression' => $this->expressionResolver->resolve($address, $ctx),
            'spell'      => $this->spellResolver->resolve($address, $ctx),
            'list'       => $this->listResolver->resolve($address, $ctx),
            'generic'    => $this->genericEventResolver->resolve($address, $ctx),
        };
    }

    /**
     * Dispatch an already-resolved Event by its kind, bypassing DB lookups.
     *
     * @return NormalizedItem[]
     */
    public function resolveEvent(Event $event, RuntimeContext $ctx): array
    {
        $kind = $event->getKind();
        $resolverType = $this->resolverTypeForKind($kind);

        $this->logger->debug('Dispatching pre-resolved event by kind', [
            'eventId' => $event->getId(),
            'kind' => $kind,
            'resolver' => $resolverType,
        ]);

        return match ($resolverType) {
            'expression' => $this->expressionResolver->executeEvent($event, $ctx),
            'spell'      => $this->spellResolver->executeEvent($event, $ctx),
            'list'       => $this->listResolver->executeEvent($event, $ctx),
            'generic'    => [new NormalizedItem($event)],
        };
    }

    private function resolverTypeForKind(int $kind): string
    {
        return match (true) {
            $kind === 30880 => 'expression',
            $kind === 777   => 'spell',
            in_array($kind, self::LIST_KINDS, true) => 'list',
            default         => 'generic',
        };
    }
}
