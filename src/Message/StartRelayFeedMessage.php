<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when a user requests a live feed from an arbitrary Nostr relay.
 *
 * The handler opens a time-bounded WebSocket subscription (kind 30023),
 * extracts raw article metadata and pushes each event to:
 *   - a Mercure topic  (/relay-feed/{relayKey})  so open browser tabs update live
 *   - a Redis rolling buffer                      so late-joining tabs see recent history
 *
 * After the window elapses the handler re-dispatches itself as long as the feed
 * page is still being actively viewed (signalled via the Redis active flag).
 */
final class StartRelayFeedMessage
{
    public function __construct(
        public readonly string $relayUrl,
        public readonly string $relayKey,
    ) {}
}

