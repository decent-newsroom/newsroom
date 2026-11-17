<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after a newly-persisted Nostr event may need to be delivered as an
 * update to users who subscribed to a matching source (npub, publication
 * coordinate, or NIP-51 set).
 *
 * Shared local change records determine which revisions need delivery.
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
