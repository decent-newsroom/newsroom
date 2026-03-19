<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after SyncUserEventsHandler completes to proactively build
 * the per-user consolidated follows relay pool.
 *
 * The pool is a deduplicated, health-ranked set of relay URLs covering
 * all followed authors' declared write relays. It is cached in Redis
 * with a 30-day TTL and keyed to the kind 3 event ID, so it only
 * rebuilds when the follows list actually changes.
 *
 * Handler: App\MessageHandler\WarmFollowsRelayPoolHandler
 */
class WarmFollowsRelayPoolMessage
{
    public function __construct(
        public readonly string $pubkeyHex,
    ) {}
}

