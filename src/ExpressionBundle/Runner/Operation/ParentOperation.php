<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;
use App\ExpressionBundle\Runner\Traversal\TraversalResolver;

/**
 * NIP-GX `parent` — one-hop upward traversal.
 *
 * Each input contributes zero or more events: at most one for tree-shaped
 * kinds (kind:1, kind:1111) and potentially several for inclusion-based
 * graphs (kind:30040 — multiple publications may include the same item).
 */
final class ParentOperation implements OperationInterface
{
    public function __construct(
        private readonly TraversalResolver $resolver,
    ) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('parent requires exactly 1 input');
        }

        // Batch-hydrate every declared parent reference across the whole input
        // set in one round-trip (one query for event-id refs, one for
        // addressable coordinate refs), so the per-item parents() calls below
        // all hit the resolver's in-memory cache.
        $this->resolver->prefetchForParents($inputs[0]);

        $result = [];
        foreach ($inputs[0] as $item) {
            foreach ($this->resolver->parents($item) as $parent) {
                $result[] = $parent;
            }
        }
        return $result;
    }
}

