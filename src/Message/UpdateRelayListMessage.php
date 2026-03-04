<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched on login to asynchronously warm the user's relay list.
 *
 * Handler calls UserRelayListService::revalidate() which fetches from
 * profile relays, persists to DB, and warms the Redis/PSR-6 cache.
 * By the time the user navigates to follows/editor, relay data is warm.
 */
class UpdateRelayListMessage
{
    public function __construct(
        public readonly string $pubkeyHex,
    ) {}
}

