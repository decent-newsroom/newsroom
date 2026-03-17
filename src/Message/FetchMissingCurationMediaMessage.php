<?php

declare(strict_types=1);

namespace App\Message;

final class FetchMissingCurationMediaMessage
{
    /**
     * @param string[] $eventIds
     * @param string[] $relays
     */
    public function __construct(
        public readonly string $curationId,
        public readonly array $eventIds,
        public readonly array $relays = [],
    ) {}
}

