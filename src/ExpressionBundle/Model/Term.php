<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model;

/**
 * NIP-FX scoring term.
 */
final class Term
{
    /**
     * @param string $namespace "prop", "tag", or "" (derived)
     * @param string $selector Field or tag name
     * @param string $normalizer Normalizer name: identity, recency, log, in, contains-ci, count
     * @param float $weight Weight factor [-1000, 1000]
     * @param string[] $extraValues Additional values for the normalizer (half-life, comparison set, kinds)
     */
    public function __construct(
        public readonly string $namespace,
        public readonly string $selector,
        public readonly string $normalizer,
        public readonly float $weight,
        public readonly array $extraValues = [],
    ) {}
}

