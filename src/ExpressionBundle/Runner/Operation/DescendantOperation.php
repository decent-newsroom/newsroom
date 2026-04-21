<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;
use App\ExpressionBundle\Runner\Traversal\TraversalResolver;

/**
 * NIP-GX `descendant` — transitive downward traversal.
 *
 * Depth-first expansion using each kind's child ordering rule. With modifier
 * `leaves`, only terminal descendants (nodes with no children under this NIP)
 * are emitted; otherwise all descendants are emitted in DFS order.
 *
 * Per-item visited set prevents cycles; global depth is bounded to protect
 * the runner against pathological event chains.
 */
final class DescendantOperation implements OperationInterface
{
    private const MAX_DEPTH = 32;
    private const MAX_NODES_PER_INPUT = 5000;

    public function __construct(
        private readonly TraversalResolver $resolver,
    ) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('descendant requires exactly 1 input');
        }

        $leavesOnly = $stage->traversalModifier === 'leaves';
        $out = [];

        foreach ($inputs[0] as $item) {
            $visited = [$item->getCanonicalId() => true];
            $produced = 0;
            $this->dfs($item, $visited, $produced, 0, $leavesOnly, $out);
        }

        return $out;
    }

    /**
     * @param array<string,bool> $visited
     * @param NormalizedItem[] $out
     */
    private function dfs(
        NormalizedItem $node,
        array &$visited,
        int &$produced,
        int $depth,
        bool $leavesOnly,
        array &$out,
    ): void {
        if ($depth >= self::MAX_DEPTH || $produced >= self::MAX_NODES_PER_INPUT) {
            return;
        }

        $children = $this->resolver->children($node);

        if (empty($children)) {
            // Leaf under this NIP. The seed node is never emitted (it's the input).
            if ($leavesOnly && $depth > 0) {
                $out[] = $node;
                $produced++;
            }
            return;
        }

        foreach ($children as $child) {
            $id = $child->getCanonicalId();
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            if (!$leavesOnly) {
                $out[] = $child;
                $produced++;
                if ($produced >= self::MAX_NODES_PER_INPUT) {
                    return;
                }
            }

            $this->dfs($child, $visited, $produced, $depth + 1, $leavesOnly, $out);
        }
    }
}

