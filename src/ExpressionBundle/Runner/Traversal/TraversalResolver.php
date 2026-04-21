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

    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

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
     * @return NormalizedItem[]
     */
    public function children(NormalizedItem $item): array
    {
        $kind = $item->getKind();

        return match ($kind) {
            1 => $this->kind1Children($item),
            1111 => $this->kind1111Children($item),
            30040 => $this->kind30040Children($item),
            default => [],
        };
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

        $event = $this->eventRepository->findById($parentId);
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
                $event = $this->eventRepository->findById($tag[1]);
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
                $event = $this->eventRepository->findById($tag[1]);
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
            $event = $this->eventRepository->findById($eventIdRoot);
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
        $parts = explode(':', $address, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$kind, $pubkey, $d] = $parts;
        if (!ctype_digit($kind)) {
            return null;
        }

        $event = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
        return $event !== null ? new NormalizedItem($event) : null;
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


