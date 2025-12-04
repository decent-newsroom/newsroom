<?php

namespace App\ReadModel\RedisView;

/**
 * Redis view model for Highlight entity (kind 9802, NIP-84)
 * Compact representation of highlight event
 */
final readonly class RedisHighlightView
{
    public function __construct(
        public string $eventId,                     // highlight event id
        public string $pubkey,                      // highlight author pubkey
        public \DateTimeImmutable $createdAt,
        public ?string $content = null,             // optional note/comment
        public ?string $context = null,             // highlighted text
        public array $refs = [],                    // article references
    ) {}
}

