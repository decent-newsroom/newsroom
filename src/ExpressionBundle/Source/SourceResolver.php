<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use Psr\Log\LoggerInterface;
use swentel\nostr\Nip19\Nip19Helper;
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
        // Detect and decode bech32-encoded references (nevent1..., naddr1..., note1...)
        $inputRef = $this->decodeBech32Input($inputRef);

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

    /**
     * If the reference value is a bech32-encoded Nostr identifier (nevent, naddr, note),
     * decode it and convert to the appropriate [type, reference] pair.
     */
    private function decodeBech32Input(array $inputRef): array
    {
        $ref = $inputRef[1] ?? '';

        // Strip nostr: prefix if present
        if (str_starts_with($ref, 'nostr:')) {
            $ref = substr($ref, 6);
        }

        if (str_starts_with($ref, 'nevent1') || str_starts_with($ref, 'note1')) {
            try {
                $nip19 = new Nip19Helper();
                $decoded = $nip19->decode($ref);
                if (isset($decoded['event_id'])) {
                    return ['e', $decoded['event_id']];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to decode bech32 nevent/note', [
                    'ref' => substr($ref, 0, 64),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (str_starts_with($ref, 'naddr1')) {
            try {
                $nip19 = new Nip19Helper();
                $decoded = $nip19->decode($ref);
                if (isset($decoded['kind'], $decoded['author'], $decoded['identifier'])) {
                    $address = $decoded['kind'] . ':' . $decoded['author'] . ':' . $decoded['identifier'];
                    return ['a', $address];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to decode bech32 naddr', [
                    'ref' => substr($ref, 0, 64),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $inputRef;
    }
}
