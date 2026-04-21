<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;
use App\ExpressionBundle\Runner\Traversal\TraversalResolver;

/**
 * NIP-GX `child` — one-hop downward traversal.
 *
 * Children of kind:1 and kind:1111 are emitted in ascending (created_at, id)
 * order; children of kind:30040 are emitted in `a`-tag declaration order
 * (this ordering is produced by TraversalResolver).
 */
final class ChildOperation implements OperationInterface
{
    public function __construct(
        private readonly TraversalResolver $resolver,
    ) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('child requires exactly 1 input');
        }

        $result = [];
        foreach ($inputs[0] as $item) {
            foreach ($this->resolver->children($item) as $child) {
                $result[] = $child;
            }
        }
        return $result;
    }
}

