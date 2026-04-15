<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model\Clause;

/**
 * ["cmp","prop"|"tag"|"",selector,comparator,value]
 */
final class CmpClause implements ClauseInterface
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $selector,
        public readonly string $comparator,
        public readonly string $value,
    ) {}
}

