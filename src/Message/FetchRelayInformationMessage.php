<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async dispatch to fetch a single relay's NIP-11 document.
 *
 * Routed to the `async_low_priority` transport so admin "Refresh now"
 * clicks return 200 immediately and the actual HTTP fetch happens in the
 * worker.
 */
final class FetchRelayInformationMessage
{
    public function __construct(
        public readonly string $relayUrl,
    ) {}
}

