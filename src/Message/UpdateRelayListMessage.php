<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched on login to asynchronously warm the user's relay list.
 *
 * Handler calls UserRelayListService::revalidate() which fetches from
 * profile relays, persists to DB, and warms the Redis/PSR-6 cache.
 * By the time the user navigates to follows/editor, relay data is warm.
 *
 * When $fullSync is true (e.g. manual "Sync from relays" button), the
 * downstream SyncUserEventsMessage is dispatched without a `since` filter,
 * fetching all replaceable events regardless of age. The login flow sets
 * $fullSync = false so only events from the last 24 hours are fetched.
 */
class UpdateRelayListMessage
{
    public function __construct(
        public readonly string $pubkeyHex,
        public readonly bool   $fullSync = false,
    ) {}
}

