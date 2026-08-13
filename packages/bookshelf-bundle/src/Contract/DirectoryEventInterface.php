<?php

declare(strict_types=1);

namespace DecentNewsroom\BookshelfBundle\Contract;

/**
 * Minimal read-only view of a stored Nostr event needed to resolve a user's
 * NKBIP-04 bookshelf directory (kind 30045).
 */
interface DirectoryEventInterface
{
    /**
     * The event's `d` tag value, if any.
     */
    public function getDTag(): ?string;

    /**
     * Legacy/alternate accessor some hosts use instead of getDTag().
     * Implementations may simply delegate to getDTag().
     */
    public function getSlug(): ?string;

    /** @return array<int, array<int, string>> */
    public function getTags(): array;
}
