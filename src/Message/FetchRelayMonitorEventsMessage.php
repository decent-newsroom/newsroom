<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async dispatch to fetch a trusted monitor's NIP-66 events (kinds 10166 + 30166)
 * from external relays and project them into the database.
 *
 * Dispatched automatically when a monitor pubkey is trusted via the admin UI,
 * and can be dispatched manually via the `relay:fetch-monitor-events` CLI command
 * to backfill existing trusted monitors.
 *
 * Routed to the `async_low_priority` transport so the admin "Trust" action
 * returns immediately and the relay fetch happens in the background worker.
 */
final class FetchRelayMonitorEventsMessage
{
    public function __construct(
        public readonly string $pubkeyHex,
    ) {}
}

