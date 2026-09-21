<?php

namespace App\Message;

/**
 * Message to trigger async profile projection update for a user.
 *
 * Dispatched when:
 * - A user logs in for the first time or after a period
 * - A kind:0 (metadata) or kind:10002 (relay list) event is ingested
 * - Manual profile refresh is requested
 */
class UpdateProfileProjectionMessage
{
    /**
     * @param string[] $relayHints
     */
    public function __construct(
        private readonly string $pubkeyHex,
        private readonly array $relayHints = [],
    ) {
    }

    public function getPubkeyHex(): string
    {
        return $this->pubkeyHex;
    }

    /**
     * @return string[]
     */
    public function getRelayHints(): array
    {
        return $this->relayHints;
    }
}
