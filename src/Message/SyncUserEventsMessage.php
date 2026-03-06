<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after relay-list warming on login to batch-fetch the logged-in
 * user's own events from their NIP-65 relays via the relay gateway.
 *
 * A single REQ with `authors: [$pubkey]` and a wide kind filter covers
 * all event types the app cares about in one round-trip, replacing the
 * previous per-content-type fan-out pattern.
 *
 * Handler: App\MessageHandler\SyncUserEventsHandler
 */
class SyncUserEventsMessage
{
    /**
     * @param string   $pubkeyHex  Hex pubkey of the logged-in user
     * @param string[] $relayUrls  NIP-65 read relays (already resolved by UpdateRelayListHandler)
     * @param int      $since      Unix timestamp — only fetch events newer than this (0 = all time)
     */
    public function __construct(
        public readonly string $pubkeyHex,
        public readonly array  $relayUrls,
        public readonly int    $since = 0,
    ) {}
}

