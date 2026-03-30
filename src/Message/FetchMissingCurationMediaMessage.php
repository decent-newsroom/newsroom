<?php

declare(strict_types=1);

namespace App\Message;

final class FetchMissingCurationMediaMessage
{
    /**
     * @param string[] $eventIds     Missing e-tag event IDs
     * @param string[] $relays       Relay hints from e-tag / a-tag / relay list
     * @param string   $authorPubkey Hex pubkey of the curation board author
     * @param string[] $coordinates  Missing a-tag coordinates ("kind:pubkey:identifier")
     */
    public function __construct(
        public readonly string $curationId,
        public readonly array $eventIds,
        public readonly array $relays = [],
        public readonly string $authorPubkey = '',
        public readonly array $coordinates = [],
    ) {}
}

