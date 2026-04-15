<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model\Clause;

/**
 * ["not","prop"|"tag",selector,...values]
 */
final class NotClause implements ClauseInterface
{
    /** @param string[] $resolvedValues */
    public function __construct(
        public readonly string $namespace,
        public readonly string $selector,
        public readonly array $resolvedValues,
    ) {}
}

