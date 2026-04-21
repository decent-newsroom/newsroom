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

        // Batch-hydrate every declared parent / root reference across the
        // whole input set in one round-trip (one query for event-id refs,
        // one for addressable coordinate refs). Subsequent rootHint()
        // and walkAncestors() per-item calls hit the in-memory cache.
        $this->resolver->prefetchForParents($inputs[0]);

        foreach ($inputs[0] as $item) {
            if ($rootOnly) {
                // `ancestor root` per NIP-GX: "Returns only the farthest
                // ancestor." We climb hop-by-hop from the input, taking the
                // first resolvable parent at each step. When the current node
                // has a declared parent/root that cannot be resolved (gap in
                // the local DB), we consult the kind's root-tag hint (NIP-10
                // marked root, NIP-22 uppercase E/A) to jump over the gap
                // and continue from there. We do NOT use the root hint as a
                // blind fast-path at the very first hop: many NIP-22 clients
                // set uppercase E to "the thing I replied to" rather than
                // the true thread root, so short-circuiting on it lands
                // kind:1111 comments at the top of feeds where the user
                // actually wants the originating article/note.
                $farthest = $this->climbToRoot($item);
                if ($farthest !== null) {
                    $out[] = $farthest;
                    continue;
                }

                // Chain empty AND no resolvable root hint. NIP-GX's "is its
                // own root" totality clause only fires when the input has
                // NO declared parent/root; a declared-but-unresolvable ref
                // yields no event (per the address-resolution rule).
                // Additionally, kind-specific rules in canBeSelfRoot() reject
                // items that are clearly not thread origins (e.g. kind:1
                // notes with no `a` tag associating them with an article),
                // so `ancestor root` feeds don't fill up with off-topic
                // standalone notes.
                if (!$this->resolver->hasDeclaredRoot($item) && $this->resolver->canBeSelfRoot($item)) {
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
            // Prefetch next-level parent refs for the whole frontier in one
            // batched SQL call before we iterate. Without this, deeply-nested
            // threads do one DB round-trip per hop per item.
            $this->resolver->prefetchForParents($frontier);

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

    /**
     * Iteratively climb from the seed to the farthest reachable ancestor.
     *
     * At each step:
     *   1. If the current node has resolvable parents, take the first one and
     *      continue.
     *   2. If not, but the node declares a root-scope tag (NIP-10 marked
     *      "root" e, NIP-22 uppercase E/A), jump to the hint's target and
     *      continue climbing FROM THAT event. Misconfigured NIP-22 clients
     *      sometimes chain root scopes (uppercase E pointing at another
     *      comment instead of the thread's true origin), so we don't stop
     *      at the first hint — we keep going until no further progress is
     *      possible.
     *   3. Otherwise the walk terminates.
     *
     * Returns null if:
     *   - the seed has no declared parent/root (caller uses the NIP-GX
     *     totality clause to emit the seed itself), or
     *   - the seed declares a parent/root that cannot be resolved anywhere
     *     (no step made progress past the seed) — per the address-resolution
    *     rule, such items yield no event, or
     *   - the walk advanced but stopped at an intermediate node that *itself*
     *     declares an unresolvable root. A kind:1111 comment (or any kind with
     *     defined upward traversal) is never a legitimate "root" if it still
     *     has something declared upstream that we can't see — surfacing it
     *     would leak intermediate comments into feeds that asked for thread
     *     roots. We only accept `$current` as the farthest ancestor when it
     *     genuinely has no further declared upstream.
     *
     * Visited set is keyed on canonical id and guards against cycles and
     * self-referential declared roots.
     */
    private function climbToRoot(NormalizedItem $seed): ?NormalizedItem
    {
        $current = $seed;
        $visited = [$seed->getCanonicalId() => true];
        $advanced = false;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $parents = $this->resolver->parents($current);
            if (!empty($parents)) {
                // Pick the first not-yet-visited parent. Tree-shaped kinds
                // (kind:1, kind:1111) have at most one anyway. For inclusion-
                // based graphs (kind:30040) multiple parents are possible;
                // taking the first gives a deterministic single answer,
                // consistent with NIP-GX's "exactly one event" totality
                // for the `root` modifier.
                $progressed = false;
                foreach ($parents as $parent) {
                    $pid = $parent->getCanonicalId();
                    if (isset($visited[$pid])) {
                        continue;
                    }
                    $visited[$pid] = true;
                    $current = $parent;
                    $advanced = true;
                    $progressed = true;
                    break;
                }
                if ($progressed) {
                    continue;
                }
                // All parents already visited — cycle or back-reference.
                return $this->terminate($current, $advanced);
            }

            // No resolvable parent. If the current node declares a root-scope
            // tag, jump to it (skipping over any gap in the local DB).
            if ($this->resolver->hasDeclaredRoot($current)) {
                $hint = $this->resolver->rootHint($current);
                if ($hint !== null) {
                    $hid = $hint->getCanonicalId();
                    if (!isset($visited[$hid])) {
                        $visited[$hid] = true;
                        $current = $hint;
                        $advanced = true;
                        continue;
                    }
                }
                // Root declared but unresolvable (or already visited). Per
                // NIP-GX's address-resolution rule, items whose declared
                // root cannot be reached yield no event — including the
                // intermediate node we got stuck on, if IT still declares
                // something upstream. See {@see terminate()}.
                return $this->terminate($current, $advanced);
            }

            // No parents, no declared root: genuinely at the top.
            return $this->terminate($current, $advanced);
        }

        // Safety: depth cap reached.
        return $this->terminate($current, $advanced);
    }

    /**
     * Decide whether the final $current may be emitted as the farthest
     * ancestor, given whether the walk advanced past the seed.
     *
     * Rules:
     *   - If the walk never advanced (no hop succeeded), emit nothing. The
     *     caller will fall back to the NIP-GX totality clause only when the
     *     seed has no declared parent/root.
     *   - If the walk advanced but the final node still declares upstream
     *     references we couldn't resolve (e.g. a nested kind:1111 whose
     *     own parent is missing from the local DB), emit nothing. Surfacing
     *     an intermediate kind:1111 as "root" would leak comments into
     *     feeds that explicitly asked for thread origins, contradicting
     *     both the intent of `ancestor root` and NIP-GX's address-resolution
     *     rule.
     *   - Otherwise, the final node has no further declared upstream: it is
     *     a legitimate root under this NIP and we emit it.
     */
    private function terminate(NormalizedItem $current, bool $advanced): ?NormalizedItem
    {
        if (!$advanced) {
            return null;
        }
        if ($this->resolver->hasDeclaredRoot($current)) {
            return null;
        }
        return $current;
    }
}

