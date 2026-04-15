<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model;

/**
 * Ordered list of Stage objects — the parsed AST of a kind:30880 expression.
 */
final class Pipeline
{
    /** @param Stage[] $stages */
    public function __construct(
        public readonly string $dTag,
        public readonly array $stages,
    ) {}
}

