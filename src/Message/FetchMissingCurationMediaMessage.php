<?php

declare(strict_types=1);

namespace App\Message;

final class FetchMissingCurationMediaMessage
{
    /**
     * @param string[] $eventIds
     * @param string[] $relays
     * @param string   $authorPubkey  Hex pubkey of the curation board author
     */
    public function __construct(
        public readonly string $curationId,
        public readonly array $eventIds,
        public readonly array $relays = [],
        public readonly string $authorPubkey = '',
    ) {}
}

