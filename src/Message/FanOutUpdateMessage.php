<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a newly-persisted Nostr event may need to be delivered as an
 * update to users who subscribed to a matching source (npub, publication
 * coordinate, or NIP-51 set).
 *
 * v1 notified kinds: 30023 (longform) and 30040 (publication index).
 */
final class FanOutUpdateMessage
{
    public function __construct(
        private readonly string $eventId,
    ) {
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }
}

