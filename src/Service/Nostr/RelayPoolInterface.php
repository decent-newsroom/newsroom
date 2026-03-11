<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\RelayPurpose;
use swentel\nostr\Relay\Relay;

/**
 * Formal interface for the Nostr relay connection pool.
 *
 * Extracted from NostrRelayPool for testability and potential future swap.
 * Implemented by NostrRelayPool.
 */
interface RelayPoolInterface
{
    /**
     * Get (or create) a single relay connection by URL.
     */
    public function getRelay(string $url): Relay;

    /**
     * Get multiple relay connections, local relay first.
     *
     * External relays are sorted by health score (highest first).
     *
     * @param string[] $urls
     * @return Relay[]
     */
    public function getRelays(array $urls): array;

    /**
     * Send a request to one or more relays and collect responses.
     *
     * When the gateway is enabled, external relays are routed through it.
     * The local relay always goes direct.
     *
     * @param string[]  $relayUrls
     * @param callable  $messageBuilder   Builds the message to send
     * @param int|null  $timeout          Optional per-relay timeout
     * @param string|null $subscriptionId Optional sub ID to CLOSE after EOSE
     * @param string|null $pubkey         User pubkey for AUTH-gated routing
     * @return array
     */
    public function sendToRelays(
        array $relayUrls,
        callable $messageBuilder,
        ?int $timeout = null,
        ?string $subscriptionId = null,
        ?string $pubkey = null,
    ): array;

    /**
     * Publish a signed event to one or more relays.
     *
     * @param string[] $relayUrls
     * @param string|null $pubkey User pubkey for gateway routing
     * @return array Per-relay publish results
     */
    public function publish(\swentel\nostr\Event\Event $event, array $relayUrls, ?string $pubkey = null, int $timeout = 30): array;

    /**
     * Close and remove a relay connection from the pool.
     */
    public function closeRelay(string $relayUrl): void;

    /**
     * Close all open relay connections.
     */
    public function closeAll(): void;

    /**
     * Return pool statistics: active_connections count + per-relay details.
     */
    public function getStats(): array;

    /**
     * Return the configured local relay URL, or null if not set.
     */
    public function getLocalRelay(): ?string;

    /**
     * Whether the relay gateway is enabled.
     */
    public function isGatewayEnabled(): bool;
}

