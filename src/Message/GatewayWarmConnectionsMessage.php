<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched after relay list warming to tell the relay gateway
 * to open and authenticate user-specific connections to AUTH-gated relays.
 *
 * The handler writes a "warm" command to the relay:control Redis Stream.
 * The gateway picks it up and opens persistent WebSocket connections
 * authenticated as the user's npub via Mercure roundtrip.
 */
class GatewayWarmConnectionsMessage
{
    /**
     * @param string   $pubkeyHex      User's hex pubkey
     * @param string[] $authRelayUrls   Relay URLs known to require AUTH
     */
    public function __construct(
        public readonly string $pubkeyHex,
        public readonly array $authRelayUrls,
    ) {}
}

