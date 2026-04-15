<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model\Clause;

/**
 * ["text","prop"|"tag",selector,mode,value]
 */
final class TextClause implements ClauseInterface
{
    public function __construct(
        public readonly string $namespace,
        public readonly string $selector,
        public readonly string $mode,
        public readonly string $value,
    ) {}
}

