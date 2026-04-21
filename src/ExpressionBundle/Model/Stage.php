<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model;

use App\ExpressionBundle\Model\Clause\ClauseInterface;

/**
 * One pipeline stage: operation name + stage-local configuration.
 */
final class Stage
{
    /**
     * @param string $op Operation name: "all", "any", "none", "sort", "slice", "distinct", "union", "intersect", "difference", "score", "parent", "child", "ancestor", "descendant"
     * @param array $inputs Input references [["e","<id>"], ["a","<addr>"]]
     * @param ClauseInterface[] $clauses Clause objects for filter operations
     * @param Term[] $terms Term objects for NIP-FX score operation
     * @param ?string $traversalModifier NIP-GX traversal modifier: "root" for ancestor, "leaves" for descendant; null otherwise
     */
    public function __construct(
        public readonly string $op,
        public readonly array $inputs = [],
        public readonly array $clauses = [],
        public readonly array $terms = [],
        public readonly ?string $sortNamespace = null,
        public readonly ?string $sortField = null,
        public readonly ?string $sortDirection = null,
        public readonly ?string $sortMode = null,
        public readonly ?int $sliceOffset = null,
        public readonly ?int $sliceLimit = null,
        public readonly ?string $traversalModifier = null,
    ) {}
}

