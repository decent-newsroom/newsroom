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


