<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Main dispatcher: routes input references to specialized resolvers.
 *
 * Lazy because ExpressionSourceResolver depends on SourceResolverInterface,
 * creating a construction-time cycle that the proxy breaks.
 */
#[Autoconfigure(lazy: SourceResolverInterface::class)]
final class SourceResolver implements SourceResolverInterface
{
    public function __construct(
        private readonly EventIdSourceResolver $eventIdResolver,
        private readonly AddressSourceResolver $addressResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Kinds that are "containers" — resolving by event ID should expand their
     * contents via AddressSourceResolver instead of returning the event itself.
     */
    private const DELEGATABLE_KINDS = [
        777,                        // spell
        30880,                      // expression
        30003, 30004, 30005, 30006, // curation sets
        10003,                      // bookmarks
    ];

    /** @return NormalizedItem[] */
    public function resolve(array $inputRef, RuntimeContext $ctx): array
    {
        $this->logger->debug('Resolving input reference', [
            'type' => $inputRef[0],
            'ref' => substr($inputRef[1] ?? '', 0, 64),
        ]);

        $items = match ($inputRef[0]) {
            'e' => $this->eventIdResolver->resolve($inputRef[1], $ctx),
            'a' => $this->addressResolver->resolve($inputRef[1], $ctx),
            default => throw new InvalidArgumentException("Unknown input type: {$inputRef[0]}"),
        };

        // When an event ID resolves to a "container" kind (spell, expression, list),
        // delegate to AddressSourceResolver to expand it into its contents.
        // Uses resolveEvent() to pass the already-fetched Event directly,
        // avoiding a DB re-lookup that would fail for relay-only events.
        if ($inputRef[0] === 'e' && count($items) === 1) {
            $item = $items[0];
            $kind = $item->getKind();

            if (in_array($kind, self::DELEGATABLE_KINDS, true)) {
                $this->logger->debug('Event ID resolved to container kind, delegating to address resolver', [
                    'eventId' => $inputRef[1],
                    'kind' => $kind,
                ]);

                return $this->addressResolver->resolveEvent($item->getEvent(), $ctx);
            }
        }

        return $items;
    }
}
