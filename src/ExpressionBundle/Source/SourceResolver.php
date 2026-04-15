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

    /** @return NormalizedItem[] */
    public function resolve(array $inputRef, RuntimeContext $ctx): array
    {
        $this->logger->debug('Resolving input reference', [
            'type' => $inputRef[0],
            'ref' => substr($inputRef[1] ?? '', 0, 64),
        ]);

        return match ($inputRef[0]) {
            'e' => $this->eventIdResolver->resolve($inputRef[1], $ctx),
            'a' => $this->addressResolver->resolve($inputRef[1], $ctx),
            default => throw new InvalidArgumentException("Unknown input type: {$inputRef[0]}"),
        };
    }
}
