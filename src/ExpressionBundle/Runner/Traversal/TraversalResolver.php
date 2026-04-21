<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Runner\Traversal;

use App\Entity\Event;
use App\ExpressionBundle\Model\NormalizedItem;
use App\Repository\EventRepository;

/**
 * NIP-GX graph traversal: resolves parent/child relations for supported kinds.
 *
 * Supported kinds:
 *   - kind:1    (NIP-10 threaded replies, marked form; deprecated positional form not supported)
 *   - kind:1111 (NIP-22 scoped comments via lowercase e/a/i tags; only e/a participate)
 *   - kind:30040 (NKBIP-01 publication indices; inclusion via a-tags in declaration order)
 *
 * Downward lookups are best-effort and DB-only (no relay fetches), consistent
 * with the "Best-effort semantics" section of NIP-GX and with NIP-FX `count`.
 */
final class TraversalResolver
{
    /** Upper bound on children returned per input item. */
    private const MAX_CHILDREN = 500;

    /**
     * Per-evaluation cache for id / coord → Event lookups.
     *
     * Keys:
     *   - `id:<hex>`  → Event or false (cached miss)
     *   - `a:<kind:pubkey:d>` → Event or false (cached miss)
     *
     * Populated by {@see prefetchForParents()} / {@see prefetchForChildren()}
     * and consulted by the internal {@see lookupById()} / {@see lookupByCoord()}
     * helpers, so that the iterative per-item traversal code never re-hits the
     * DB for events already fetched during this run.
     *
     * @var array<string, Event|false>
     */
    private array $eventCache = [];

    /**
     * Per-evaluation cache for batched child lookups.
     *
     * Key is the parent NormalizedItem's canonical id; value is the full
     * sorted list of children resolved in {@see prefetchForChildren()}.
     * Consulted by {@see children()} so a downstream DFS / BFS never
     * re-queries the DB for a node it has already seen.
     *
     * @var array<string, NormalizedItem[]>
     */
    private array $childrenCache = [];

    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

    /**
     * Drop all per-evaluation caches. Called by the runner at the start of
     * every pipeline run — the resolver is a shared service and must not
     * leak lookups from one evaluation to the next (the DB may have changed
     * between runs, and we never want a stale hit).
     */
    public function resetCache(): void
    {
        $this->eventCache = [];
        $this->childrenCache = [];
    }

    /**
     * Return the direct parents of a single input item.
     *
     * Most graphs produce at most one parent (tree-shaped); kind:30040 inclusion
     * can produce multiple (an item can be included in several publications).
     *
     * @return NormalizedItem[]
     */
    public function parents(NormalizedItem $item): array
    {
        $kind = $item->getKind();

        // Tree-shaped kinds: parent is declared on the item itself.
        if ($kind === 1) {
            return $this->singletonOrEmpty($this->kind1Parent($item));
        }
        if ($kind === 1111) {
            return $this->singletonOrEmpty($this->kind1111Parent($item));
        }

        // Inclusion-based parents (kind:30040 indexing any addressable event).
        return $this->inclusionParents($item);
    }

    /**
     * Return the direct children of a single input item in canonical order.
     *
     * Reads from the per-evaluation children cache when populated by
     * {@see prefetchForChildren()}; falls back to an online per-item lookup
     * otherwise. The result is memoized so repeated calls for the same node
     * (e.g. during {@see DescendantOperation}'s DFS) are free.
     *
     * @return NormalizedItem[]
     */
    public function children(NormalizedItem $item): array
    {
        $cid = $item->getCanonicalId();
        if (isset($this->childrenCache[$cid])) {
            return $this->childrenCache[$cid];
        }

        $children = match ($item->getKind()) {
            1 => $this->kind1Children($item),
            1111 => $this->kind1111Children($item),
            30040 => $this->kind30040Children($item),
            default => [],
        };

        $this->childrenCache[$cid] = $children;
        return $children;
    }

    /**
     * True if the item's kind has defined traversal semantics under this NIP.
     */
    public function isTraversable(NormalizedItem $item): bool
    {
        return in_array($item->getKind(), [1, 1111, 30040], true);
    }

    /**
     * True if the item declares a parent/root reference in its tags, regardless
     * of whether the referenced event can be resolved from the local DB.
     *
     * Used by {@see AncestorOperation} to distinguish between:
     *   - input with no declared parent/root → emit the input itself as its own
     *     root (NIP-GX "ancestor root" totality clause);
     *   - input whose declared parent/root cannot be resolved → emit nothing
     *     (NIP-GX "a coordinate that resolves to no visible event contributes
     *     no result" — we don't want the intermediate item leaking through as
     *     a "root" just because we can't see what it's really attached to).
     *
     * For `kind:30040` this is always false: its inclusion-based graph has no
     * declared root; walking up via {@see parents()} is the only correct path.
     */
    public function hasDeclaredRoot(NormalizedItem $item): bool
    {
        $tags = $item->getEvent()->getTags();

        return match ($item->getKind()) {
            // NIP-10 marked `"root"` e tag; in the absence of a marked root,
            // a marked `"reply"` still signals the item sits inside a thread.
            1 => $this->hasTagWithMarker($tags, 'e', ['root', 'reply']),

            // NIP-22: uppercase E/A (root scope) or lowercase e/a (direct parent).
            // Either one means the comment is attached to something upstream.
            1111 => $this->hasAnyTag($tags, ['E', 'A', 'e', 'a']),

            default => false,
        };
    }

    /**
     * @param array<int, array<int, string>> $tags
     * @param list<string>                   $names
     */
    private function hasAnyTag(array $tags, array $names): bool
    {
        foreach ($tags as $tag) {
            if (isset($tag[1], $tag[0]) && in_array($tag[0], $names, true) && $tag[1] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<int, string>> $tags
     * @param list<string>                   $markers
     */
    private function hasTagWithMarker(array $tags, string $name, array $markers): bool
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== $name || !isset($tag[1])) {
                continue;
            }
            if (in_array($tag[3] ?? null, $markers, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Best-effort shortcut to the "true root" of an item when the kind declares
     * an explicit root tag, used to implement `ancestor root` without having to
     * climb the parent chain one hop at a time.
     *
     * - `kind:1`    : marked `"root"` e tag (NIP-10)
     * - `kind:1111` : uppercase `E` (event-id) or `A` (addressable) root-scope tag (NIP-22)
     * - `kind:30040`: no explicit root hint (inclusion-based graph may have multiple parents;
     *                 walking up with {@see parents()} is the only correct path)
     *
     * Returns null when no root hint is declared, when the hint references a
     * non-event target (e.g. a NIP-22 `I` external identity), or when the
     * referenced event cannot be resolved from the local DB. Callers SHOULD
     * fall back to iterative traversal via {@see parents()} in that case.
     */
    public function rootHint(NormalizedItem $item): ?NormalizedItem
    {
        return match ($item->getKind()) {
            1     => $this->kind1RootHint($item),
            1111  => $this->kind1111RootHint($item),
            default => null,
        };
    }

    // ---- kind:1 (NIP-10) ------------------------------------------------

    private function kind1Parent(NormalizedItem $item): ?NormalizedItem
    {
        $reply = null;
        $root = null;

        foreach ($item->getEvent()->getTags() as $tag) {
            if (($tag[0] ?? null) !== 'e' || !isset($tag[1])) {
                continue;
            }
            $marker = $tag[3] ?? null;
            if ($marker === 'reply' && $reply === null) {
                $reply = $tag[1];
            } elseif ($marker === 'root' && $root === null) {
                $root = $tag[1];
            }
        }

        $parentId = $reply ?? $root;
        if ($parentId === null) {
            return null;
        }

        $event = $this->lookupById($parentId);
        return $event !== null ? new NormalizedItem($event) : null;
    }

    /**
     * NIP-10 marked `"root"` e tag — the thread's true root event.
     * Returns null if the marked root is missing (pre-marker notes) or the
     * referenced event is not in the local DB.
     */
    private function kind1RootHint(NormalizedItem $item): ?NormalizedItem
    {
        foreach ($item->getEvent()->getTags() as $tag) {
            if (($tag[0] ?? null) !== 'e' || !isset($tag[1])) {
                continue;
            }
            if (($tag[3] ?? null) === 'root') {
                $event = $this->lookupById($tag[1]);
                return $event !== null ? new NormalizedItem($event) : null;
            }
        }
        return null;
    }

    /** @return NormalizedItem[] */
    private function kind1Children(NormalizedItem $item): array
    {
        $events = $this->eventRepository->findReferencingEvents(
            tagName: 'e',
            tagValue: $item->getId(),
            kinds: [1],
            limit: self::MAX_CHILDREN,
        );

        $children = [];
        foreach ($events as $child) {
            $candidate = new NormalizedItem($child);
            $parent = $this->kind1Parent($candidate);
            if ($parent !== null && $parent->getId() === $item->getId()) {
                $children[] = $candidate;
            }
        }

        return $this->sortByCreatedAtThenId($children);
    }

    // ---- kind:1111 (NIP-22) ---------------------------------------------

    private function kind1111Parent(NormalizedItem $item): ?NormalizedItem
    {
        // NIP-22: lowercase `e` or `a` identify the direct parent.
        foreach ($item->getEvent()->getTags() as $tag) {
            $type = $tag[0] ?? null;
            if ($type === 'e' && isset($tag[1])) {
                $event = $this->lookupById($tag[1]);
                return $event !== null ? new NormalizedItem($event) : null;
            }
            if ($type === 'a' && isset($tag[1])) {
                return $this->resolveAddress($tag[1]);
            }
            // `i` parents are external identities; no event parent under this NIP.
        }
        return null;
    }

    /**
     * NIP-22 uppercase root-scope tag — the thread's true root, which is the
     * event (article, note, etc.) the comment thread is attached to.
     *
     * Preference order per NIP-22:
     *   - `E` (event-id root scope) over `A` (addressable root scope) when both
     *     are present; in practice NIP-22 clients set one or the other, but if
     *     both appear `E` is the concrete event and `A` is its coordinate, so
     *     `E` resolves faster.
     *   - `I` (external identity root scope) is ignored for event traversal.
     */
    private function kind1111RootHint(NormalizedItem $item): ?NormalizedItem
    {
        $eventIdRoot = null;
        $addressRoot = null;

        foreach ($item->getEvent()->getTags() as $tag) {
            $type = $tag[0] ?? null;
            if ($type === 'E' && isset($tag[1]) && $eventIdRoot === null) {
                $eventIdRoot = $tag[1];
            } elseif ($type === 'A' && isset($tag[1]) && $addressRoot === null) {
                $addressRoot = $tag[1];
            }
        }

        if ($eventIdRoot !== null) {
            $event = $this->lookupById($eventIdRoot);
            if ($event !== null) {
                return new NormalizedItem($event);
            }
        }
        if ($addressRoot !== null) {
            return $this->resolveAddress($addressRoot);
        }
        return null;
    }

    /** @return NormalizedItem[] */
    private function kind1111Children(NormalizedItem $item): array
    {
        // Candidates: kind:1111 events with a lowercase `e` matching the item id,
        // plus (if the item is addressable) a lowercase `a` matching its coordinate.
        $byId = $this->eventRepository->findReferencingEvents(
            tagName: 'e',
            tagValue: $item->getId(),
            kinds: [1111],
            limit: self::MAX_CHILDREN,
        );

        $byCoord = [];
        $coord = $item->getAddressableCoordinate();
        if ($coord !== null) {
            $byCoord = $this->eventRepository->findReferencingEvents(
                tagName: 'a',
                tagValue: $coord,
                kinds: [1111],
                limit: self::MAX_CHILDREN,
            );
        }

        $seen = [];
        $children = [];
        foreach (array_merge($byId, $byCoord) as $event) {
            if (isset($seen[$event->getId()])) {
                continue;
            }
            $seen[$event->getId()] = true;
            $candidate = new NormalizedItem($event);
            $parent = $this->kind1111Parent($candidate);
            if ($parent === null) {
                continue;
            }
            if ($parent->getId() === $item->getId()) {
                $children[] = $candidate;
                continue;
            }
            if ($coord !== null && $parent->getAddressableCoordinate() === $coord) {
                $children[] = $candidate;
            }
        }

        return $this->sortByCreatedAtThenId($children);
    }

    // ---- kind:30040 (NKBIP-01) ------------------------------------------

    /** @return NormalizedItem[] */
    private function kind30040Children(NormalizedItem $item): array
    {
        // Children are the events referenced by `a` tags, in declaration order.
        $children = [];
        foreach ($item->getEvent()->getTags() as $tag) {
            if (($tag[0] ?? null) !== 'a' || !isset($tag[1])) {
                continue;
            }
            $resolved = $this->resolveAddress($tag[1]);
            if ($resolved !== null) {
                $children[] = $resolved;
            }
        }
        return $children;
    }

    /** @return NormalizedItem[] */
    private function inclusionParents(NormalizedItem $item): array
    {
        $coord = $item->getAddressableCoordinate();
        if ($coord === null) {
            // Non-addressable events cannot be referenced by `a`-tag inclusion.
            return [];
        }

        $events = $this->eventRepository->findReferencingEvents(
            tagName: 'a',
            tagValue: $coord,
            kinds: [30040],
            limit: self::MAX_CHILDREN,
        );

        return array_map(fn(Event $e) => new NormalizedItem($e), $events);
    }

    // ---- helpers --------------------------------------------------------

    private function resolveAddress(string $address): ?NormalizedItem
    {
        $event = $this->lookupByCoord($address);
        return $event !== null ? new NormalizedItem($event) : null;
    }

    /**
     * DB lookup with per-evaluation memoization.
     *
     * Returns the Event for this id, or null if not found. A cached miss
     * (false in the cache) returns null without re-hitting the DB.
     */
    private function lookupById(string $id): ?Event
    {
        $key = 'id:' . $id;
        if (array_key_exists($key, $this->eventCache)) {
            return $this->eventCache[$key] ?: null;
        }
        $event = $this->eventRepository->findById($id);
        $this->eventCache[$key] = $event ?? false;
        return $event;
    }

    /**
     * DB lookup with per-evaluation memoization, for addressable coordinates.
     */
    private function lookupByCoord(string $coord): ?Event
    {
        $key = 'a:' . $coord;
        if (array_key_exists($key, $this->eventCache)) {
            return $this->eventCache[$key] ?: null;
        }
        $parts = explode(':', $coord, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0])) {
            $this->eventCache[$key] = false;
            return null;
        }
        $event = $this->eventRepository->findByNaddr((int) $parts[0], $parts[1], $parts[2]);
        $this->eventCache[$key] = $event ?? false;
        return $event;
    }

    /**
     * Prefetch all upward references (parents + root hints) declared by the
     * supplied items in two batched SQL round-trips (one for `e`/`E` event
     * ids, one for `a`/`A` addressable coordinates).
     *
     * After this call, the per-item parent / root-hint lookups against these
     * items are guaranteed to hit the in-memory cache, collapsing O(N) DB
     * queries into O(1). Callers invoke this once per traversal stage before
     * iterating the input set.
     *
     * Safe to call repeatedly — already-cached refs are not re-queried. Also
     * safe to call on mixed-kind input; items whose kind has no defined
     * upward graph contribute no refs.
     *
     * @param NormalizedItem[] $items
     */
    public function prefetchForParents(array $items): void
    {
        $ids = [];
        $coords = [];

        foreach ($items as $item) {
            $tags = $item->getEvent()->getTags();
            $kind = $item->getKind();

            if ($kind === 1) {
                // NIP-10: marked "reply" e-tag takes precedence over "root".
                $reply = null;
                $root = null;
                foreach ($tags as $tag) {
                    if (($tag[0] ?? null) !== 'e' || !isset($tag[1])) {
                        continue;
                    }
                    $marker = $tag[3] ?? null;
                    if ($marker === 'reply' && $reply === null) {
                        $reply = $tag[1];
                    } elseif ($marker === 'root' && $root === null) {
                        $root = $tag[1];
                    }
                }
                // The root hint is the "root"-marked event directly; also worth
                // prefetching so kind1RootHint() hits the cache.
                if ($reply !== null) {
                    $ids[$reply] = true;
                }
                if ($root !== null) {
                    $ids[$root] = true;
                }
                continue;
            }

            if ($kind === 1111) {
                // NIP-22: lowercase e/a = direct parent, uppercase E/A = root scope.
                // Collect every e/E id and a/A coordinate on this event.
                foreach ($tags as $tag) {
                    $t = $tag[0] ?? null;
                    if (!isset($tag[1])) {
                        continue;
                    }
                    if ($t === 'e' || $t === 'E') {
                        $ids[$tag[1]] = true;
                    } elseif ($t === 'a' || $t === 'A') {
                        $coords[$tag[1]] = true;
                    }
                    // `i`/`I` are external identities — not event refs.
                }
                continue;
            }

            // kind:30040 and other addressable kinds have inclusion-based
            // parents. Finding them requires a reverse index query, not an
            // id/coord lookup — handled by inclusionParents() per-item.
            // Pre-batching inclusion parents is possible (findReferencingEventsBatch
            // on the items' addressable coords) but deferred: kind:30040 parent
            // queries are rare compared to kind:1111 ancestor root.
        }

        if (!empty($ids)) {
            $needed = array_values(array_filter(
                array_keys($ids),
                fn(string $id) => !array_key_exists('id:' . $id, $this->eventCache),
            ));
            if (!empty($needed)) {
                $found = $this->eventRepository->findByIds($needed);
                foreach ($needed as $id) {
                    $this->eventCache['id:' . $id] = $found[$id] ?? false;
                }
            }
        }

        if (!empty($coords)) {
            $needed = array_values(array_filter(
                array_keys($coords),
                fn(string $c) => !array_key_exists('a:' . $c, $this->eventCache),
            ));
            if (!empty($needed)) {
                $found = $this->eventRepository->findByCoordinates($needed);
                foreach ($needed as $c) {
                    $this->eventCache['a:' . $c] = $found[$c] ?? false;
                }
            }
        }
    }

    /**
     * Prefetch the direct children for every input item in one batched SQL
     * round-trip per reference shape.
     *
     * For `kind:1` and `kind:1111` inputs: a single batched reverse-index query
     * replaces N per-item queries. Results are grouped by parent id and cached
     * by canonical id so subsequent calls to {@see children()} return from
     * memory.
     *
     * For `kind:30040` inputs: children come from the index's own `a` tags
     * (no reverse lookup needed), so we just prefetch those coordinates into
     * the event cache.
     *
     * Safe to call repeatedly; items that already have a cached children list
     * are skipped.
     *
     * @param NormalizedItem[] $items
     */
    public function prefetchForChildren(array $items): void
    {
        $k1ParentIds = [];   // kind:1 parents → find kind:1 children with e tag = these ids
        $k1111ParentIds = [];   // kind:1111 parents identified by e tag
        $k1111ParentCoords = []; // kind:1111 parents identified by a tag (addressable parent)
        $k30040ChildCoords = []; // kind:30040 inputs: prefetch their included a-tag targets
        $k1Items = [];       // parent id → NormalizedItem (for grouping)
        $k1111Items = [];    // canonical id → NormalizedItem (for grouping)

        foreach ($items as $item) {
            $cid = $item->getCanonicalId();
            if (isset($this->childrenCache[$cid])) {
                continue;
            }

            switch ($item->getKind()) {
                case 1:
                    $id = $item->getId();
                    $k1ParentIds[$id] = true;
                    $k1Items[$id] = $item;
                    break;

                case 1111:
                    $id = $item->getId();
                    $k1111ParentIds[$id] = true;
                    $k1111Items[$cid] = $item;
                    $coord = $item->getAddressableCoordinate();
                    if ($coord !== null) {
                        $k1111ParentCoords[$coord] = true;
                    }
                    break;

                case 30040:
                    foreach ($item->getEvent()->getTags() as $tag) {
                        if (($tag[0] ?? null) === 'a' && isset($tag[1])) {
                            $k30040ChildCoords[$tag[1]] = true;
                        }
                    }
                    break;
            }
        }

        // kind:30040 — prefetch referenced coordinates into the event cache.
        if (!empty($k30040ChildCoords)) {
            $needed = array_values(array_filter(
                array_keys($k30040ChildCoords),
                fn(string $c) => !array_key_exists('a:' . $c, $this->eventCache),
            ));
            if (!empty($needed)) {
                $found = $this->eventRepository->findByCoordinates($needed);
                foreach ($needed as $c) {
                    $this->eventCache['a:' . $c] = $found[$c] ?? false;
                }
            }
        }

        // kind:1 — batched reverse index: all kind:1 events that `e`-tag any of these ids.
        if (!empty($k1ParentIds)) {
            $rows = $this->eventRepository->findReferencingEventsBatch(
                tagName: 'e',
                tagValues: array_keys($k1ParentIds),
                kinds: [1],
                limit: self::MAX_CHILDREN * count($k1ParentIds),
            );
            // Group by the parent each row actually points at (marked reply/root e tag).
            foreach (array_keys($k1ParentIds) as $pid) {
                $this->childrenCache[$k1Items[$pid]->getCanonicalId()] = [];
            }
            foreach ($rows as $row) {
                $candidate = new NormalizedItem($row);
                $parent = $this->kind1Parent($candidate);
                if ($parent === null) {
                    continue;
                }
                $parentCid = $parent->getCanonicalId();
                if (isset($this->childrenCache[$parentCid])) {
                    $this->childrenCache[$parentCid][] = $candidate;
                }
            }
            foreach ($k1Items as $pItem) {
                $cid = $pItem->getCanonicalId();
                $this->childrenCache[$cid] = $this->sortByCreatedAtThenId($this->childrenCache[$cid]);
            }
        }

        // kind:1111 — one batched query for the e-tag shape, one for the a-tag
        // shape. Candidates are unioned then assigned per parent based on the
        // same kind1111Parent() rule used in the online path.
        if (!empty($k1111Items)) {
            $byIdRows = [];
            if (!empty($k1111ParentIds)) {
                $byIdRows = $this->eventRepository->findReferencingEventsBatch(
                    tagName: 'e',
                    tagValues: array_keys($k1111ParentIds),
                    kinds: [1111],
                    limit: self::MAX_CHILDREN * max(1, count($k1111ParentIds)),
                );
            }
            $byCoordRows = [];
            if (!empty($k1111ParentCoords)) {
                $byCoordRows = $this->eventRepository->findReferencingEventsBatch(
                    tagName: 'a',
                    tagValues: array_keys($k1111ParentCoords),
                    kinds: [1111],
                    limit: self::MAX_CHILDREN * max(1, count($k1111ParentCoords)),
                );
            }

            // Initialize empty buckets so parents with no children get [].
            foreach ($k1111Items as $cid => $_pItem) {
                $this->childrenCache[$cid] = [];
            }

            // Coord → canonical id index for a-tag matches.
            $cidByCoord = [];
            foreach ($k1111Items as $cid => $pItem) {
                $c = $pItem->getAddressableCoordinate();
                if ($c !== null) {
                    $cidByCoord[$c] = $cid;
                }
            }
            // Id → canonical id index for e-tag matches. (For non-addressable
            // parents, canonical id equals the event id.)
            $cidById = [];
            foreach ($k1111Items as $cid => $pItem) {
                $cidById[$pItem->getId()] = $cid;
            }

            $seen = [];
            foreach (array_merge($byIdRows, $byCoordRows) as $row) {
                $rowId = $row->getId();
                if (isset($seen[$rowId])) {
                    continue;
                }
                $seen[$rowId] = true;

                $candidate = new NormalizedItem($row);
                // Use the authoritative per-comment parent resolver.
                $parent = $this->kind1111Parent($candidate);
                if ($parent === null) {
                    continue;
                }
                $targetCid = $cidById[$parent->getId()] ?? null;
                if ($targetCid === null) {
                    $parentCoord = $parent->getAddressableCoordinate();
                    if ($parentCoord !== null) {
                        $targetCid = $cidByCoord[$parentCoord] ?? null;
                    }
                }
                if ($targetCid !== null && isset($this->childrenCache[$targetCid])) {
                    $this->childrenCache[$targetCid][] = $candidate;
                }
            }

            foreach ($k1111Items as $cid => $_pItem) {
                $this->childrenCache[$cid] = $this->sortByCreatedAtThenId($this->childrenCache[$cid]);
            }
        }
    }

    /**
     * @param NormalizedItem|null $item
     * @return NormalizedItem[]
     */
    private function singletonOrEmpty(?NormalizedItem $item): array
    {
        return $item === null ? [] : [$item];
    }

    /**
     * @param NormalizedItem[] $items
     * @return NormalizedItem[]
     */
    private function sortByCreatedAtThenId(array $items): array
    {
        usort($items, function (NormalizedItem $a, NormalizedItem $b): int {
            $cmp = $a->getCreatedAt() <=> $b->getCreatedAt();
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a->getId(), $b->getId());
        });
        return $items;
    }
}


