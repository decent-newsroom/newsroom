<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Util\RelayUrlNormalizer;
use swentel\nostr\Relay\Relay;
use WebSocket\Client as WsClient;

/**
 * Represents a single persistent WebSocket connection in the relay gateway.
 *
 * Connections are keyed by "{relayUrl}" (shared) or "{relayUrl}::{pubkeyHex}" (user-specific).
 * Shared connections use ephemeral AUTH; user connections authenticate as the user's actual npub
 * via Mercure roundtrip signing.
 *
 * Wraps a swentel\nostr\Relay object (which itself wraps a WebSocket\Client).
 * This matches the protocol patterns used by TweakedRequest and NostrRelayPool::subscribeLocal().
 */
class GatewayConnection
{
    public string $authStatus = 'none'; // 'none' | 'pending' | 'authed' | 'failed'
    public int $lastActivity;
    public int $connectedAt;
    public int $reconnectAttempts = 0;
    public bool $connected = false;

    /**
     * Persistent connections are never idle-closed. Dead persistent connections
     * are automatically reconnected during maintenance. Used for main configured
     * relays (content, profile, project, local) that are queried frequently.
     */
    public bool $persistent = false;

    /** The swentel Relay object — wraps the WebSocket client */
    public ?Relay $relay = null;

    public function __construct(
        public readonly string $relayUrl,
        public readonly ?string $pubkey, // null = shared, hex = user-specific
    ) {
        $now = time();
        $this->lastActivity = $now;
        $this->connectedAt = $now;
    }

    /**
     * Get the underlying WebSocket client from the Relay.
     */
    public function getClient(): ?WsClient
    {
        return $this->relay?->getClient();
    }

    /**
     * Build the connection pool key.
     *
     * Shared:  "wss://relay.example.com"
     * User:    "wss://relay.example.com::abc123def..."
     */
    public function getKey(): string
    {
        return self::buildKey($this->relayUrl, $this->pubkey);
    }

    public static function buildKey(string $relayUrl, ?string $pubkey = null): string
    {
        // Normalize via canonical utility so that
        // "wss://relay.damus.io" and "wss://relay.damus.io/" resolve
        // to the same connection key.
        $normalized = RelayUrlNormalizer::normalize($relayUrl);
        return $pubkey !== null ? "{$normalized}::{$pubkey}" : $normalized;
    }

    public function isShared(): bool
    {
        return $this->pubkey === null;
    }

    public function isUserConnection(): bool
    {
        return $this->pubkey !== null;
    }

    public function isAuthenticated(): bool
    {
        return $this->authStatus === 'authed';
    }

    public function isConnected(): bool
    {
        if ($this->relay === null || !$this->connected) {
            return false;
        }
        try {
            return $this->relay->isConnected();
        } catch (\Throwable) {
            $this->connected = false;
            return false;
        }
    }

    /**
     * Mark this connection as failed/disconnected.
     * Called when a send/receive throws an exception.
     */
    public function markDisconnected(): void
    {
        $this->connected = false;
    }

    public function touch(): void
    {
        $this->lastActivity = time();
    }

    public function getIdleSeconds(): int
    {
        return time() - $this->lastActivity;
    }

    /**
     * Calculate reconnect delay with exponential backoff.
     */
    public function getReconnectDelay(): int
    {
        return min(5 * (2 ** $this->reconnectAttempts), 300); // 5s, 10s, 20s, 40s, ... max 300s
    }
}

