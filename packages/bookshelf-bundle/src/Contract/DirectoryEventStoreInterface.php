<?php

declare(strict_types=1);

namespace DecentNewsroom\BookshelfBundle\Contract;

/**
 * Host-provided lookup for a user's own stored Nostr events, used to resolve
 * the latest NKBIP-04 bookshelf directory (kind 30045) for a pubkey.
 */
interface DirectoryEventStoreInterface
{
    /**
     * Find all events of a given kind authored by a pubkey, newest first.
     *
     * @return DirectoryEventInterface[]
     */
    public function findAllByPubkeyAndKind(string $pubkeyHex, int $kind, int $limit = 100): array;
}
