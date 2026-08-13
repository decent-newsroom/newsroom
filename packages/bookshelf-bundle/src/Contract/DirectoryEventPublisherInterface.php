<?php

declare(strict_types=1);

namespace DecentNewsroom\BookshelfBundle\Contract;

/**
 * Host-provided persistence and relay publishing for a signed bookshelf
 * directory event (kind 30045). The bundle itself never talks to the
 * database or relays directly.
 */
interface DirectoryEventPublisherInterface
{
    /**
     * Persist the already-signature-verified directory event locally and
     * publish it to the authenticated user's write relays.
     *
     * @param object $rawEvent Decoded Nostr event object (id, pubkey, created_at, kind, tags, content, sig).
     * @return int Number of relays that acknowledged the event.
     */
    public function publish(object $rawEvent, string $pubkeyHex): int;
}
