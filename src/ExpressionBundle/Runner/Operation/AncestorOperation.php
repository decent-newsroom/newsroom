<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Operation;

use App\ExpressionBundle\Exception\ArityException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Model\Stage;
use App\ExpressionBundle\Runner\Traversal\TraversalResolver;

/**
 * NIP-GX `ancestor` — transitive upward traversal, nearest first.
 *
 * With modifier `root`, only the farthest ancestor is emitted (or the input
 * itself if it has no parent, per NIP-GX). Without a modifier, all ancestors
 * are emitted nearest first.
 *
 * When an ancestor has multiple parents (inclusion-based graphs), each branch
 * is expanded; a per-item visited set prevents cycles.
 */
final class AncestorOperation implements OperationInterface
{
    private const MAX_DEPTH = 64;

    public function __construct(
        private readonly TraversalResolver $resolver,
    ) {}

    public function execute(array $inputs, Stage $stage, RuntimeContext $ctx): array
    {
        if (count($inputs) !== 1) {
            throw new ArityException('ancestor requires exactly 1 input');
        }

        $rootOnly = $stage->traversalModifier === 'root';
        $out = [];

        foreach ($inputs[0] as $item) {
            $chain = $this->walkAncestors($item);

            if ($rootOnly) {
                // If the input has no parent, it is its own root.
                $out[] = empty($chain) ? $item : $chain[count($chain) - 1];
                continue;
            }

            foreach ($chain as $ancestor) {
                $out[] = $ancestor;
            }
        }

        return $out;
    }

    /**
     * Walk the ancestor chain from nearest to farthest.
     *
     * For inclusion-based graphs (multiple parents), we expand breadth-first,
     * but emit nearest-first — i.e. all level-1 parents before level-2.
     *
     * @return NormalizedItem[]
     */
    private function walkAncestors(NormalizedItem $start): array
    {
        $result = [];
        $visited = [$start->getCanonicalId() => true];
        $frontier = [$start];

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $next = [];
            foreach ($frontier as $node) {
                foreach ($this->resolver->parents($node) as $parent) {
                    $id = $parent->getCanonicalId();
                    if (isset($visited[$id])) {
                        continue;
                    }
                    $visited[$id] = true;
                    $result[] = $parent;
                    $next[] = $parent;
                }
            }
            if (empty($next)) {
                break;
            }
            $frontier = $next;
        }

        return $result;
    }
}

