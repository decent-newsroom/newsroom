<?php

declare(strict_types=1);

namespace App\Message;

class SaveHighlightsMessage
{
    /**
     * @param array $events Array of Nostr event objects to save as highlights
     */
    public function __construct(
        private readonly array $events
    ) {}

    public function getEvents(): array
    {
        return $this->events;
    }
}
