<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;

/**
 * Dispatches address references (a-tag) by kind to specialized resolvers.
 */
final class AddressSourceResolver
{
    public function __construct(
        private readonly ExpressionSourceResolver $expressionResolver,
        private readonly SpellSourceResolver $spellResolver,
        private readonly ListSourceResolver $listResolver,
        private readonly GenericEventResolver $genericEventResolver,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $address, RuntimeContext $ctx): array
    {
        $parts = explode(':', $address, 3);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException("Invalid address format: {$address}");
        }

        $kind = (int) $parts[0];

        return match (true) {
            $kind === 30880 => $this->expressionResolver->resolve($address, $ctx),
            $kind === 777   => $this->spellResolver->resolve($address, $ctx),
            in_array($kind, [30003, 30004, 30005, 30006, 10003], true) => $this->listResolver->resolve($address, $ctx),
            default         => $this->genericEventResolver->resolve($address, $ctx),
        };
    }
}

