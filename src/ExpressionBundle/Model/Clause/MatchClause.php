<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model\Clause;

/**
 * ["match","prop"|"tag",selector,...values]
 */
final class MatchClause implements ClauseInterface
{
    /** @param string[] $resolvedValues */
    public function __construct(
        public readonly string $namespace,
        public readonly string $selector,
        public readonly array $resolvedValues,
    ) {}
}

