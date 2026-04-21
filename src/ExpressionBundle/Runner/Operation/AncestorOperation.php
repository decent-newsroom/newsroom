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
            if ($rootOnly) {
                // Fast path: use the kind's explicit root-tag hint when present
                // (NIP-10 marked `"root"` e tag, NIP-22 uppercase `E`/`A`). This
                // jumps directly to the true root without needing every
                // intermediate event in the local DB — the iterative walk breaks
                // as soon as one hop fails to resolve, which is the common case
                // for deeply-nested threads where middle events aren't cached.
                $hinted = $this->resolver->rootHint($item);
                if ($hinted !== null) {
                    $out[] = $hinted;
                    continue;
                }

                $chain = $this->walkAncestors($item);
                if (!empty($chain)) {
                    $out[] = $chain[count($chain) - 1];
                    continue;
                }

                // Chain empty. NIP-GX's "is its own root" totality clause only
                // applies when the input has NO declared parent/root — i.e. the
                // event genuinely starts a thread. When a parent/root IS
                // declared but cannot be resolved from the local DB, per
                // NIP-GX's address-resolution rule the stage yields no event;
                // we MUST NOT leak the intermediate comment/reply as if it
                // were the root (otherwise e.g. a kind:1111 comment whose
                // referenced article isn't cached would surface as its own
                // headline in expression results, defeating the whole point
                // of asking for the root).
                if (!$this->resolver->hasDeclaredRoot($item)) {
                    $out[] = $item;
                }
                continue;
            }

            foreach ($this->walkAncestors($item) as $ancestor) {
                $out[] = $ancestor;
            }

            // If the kind declares an explicit root tag and the iterative walk
            // didn't reach it (typically because an intermediate hop wasn't in
            // the local DB), append the true root at the farthest position so
            // the chain still terminates at it. This is a no-op when the walk
            // already emitted the root.
            $hinted = $this->resolver->rootHint($item);
            if ($hinted !== null) {
                $hintedId = $hinted->getCanonicalId();
                $alreadyEmitted = false;
                foreach ($out as $emitted) {
                    if ($emitted->getCanonicalId() === $hintedId) {
                        $alreadyEmitted = true;
                        break;
                    }
                }
                if (!$alreadyEmitted) {
                    $out[] = $hinted;
                }
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

