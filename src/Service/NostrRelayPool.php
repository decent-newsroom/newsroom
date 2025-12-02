<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use swentel\nostr\Relay\Relay;
use swentel\nostr\RelayResponse\RelayResponse;
use WebSocket\Exception\Exception;

/**
 * Manages persistent WebSocket connections to Nostr relays
 * Keeps connections alive across multiple requests to avoid reconnection overhead
 */
class NostrRelayPool
{
    /** @var array<string, Relay> Map of relay URLs to Relay instances */
    private array $relays = [];

    /** @var array<string, int> Track connection attempts for backoff */
    private array $connectionAttempts = [];

    /** @var array<string, int> Track last connection time */
    private array $lastConnected = [];

    /** @var array<string> */
    private array $defaultRelays = [];

    /** @var array<string> Default public relays to fall back to */
    private const PUBLIC_RELAYS = [
        'wss://theforest.nostr1.com',
        'wss://nostr.land',
        'wss://relay.primal.net',
    ];

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 5; // seconds
    private const CONNECTION_TIMEOUT = 30; // seconds
    private const KEEPALIVE_INTERVAL = 60; // seconds

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $nostrDefaultRelay,
        array $defaultRelays = []
    ) {
        // Build relay list: prioritize local relay, then custom relays, then public relays
        $relayList = [];

        // Add local relay first if configured
        if ($this->nostrDefaultRelay) {
            $relayList[] = $this->nostrDefaultRelay;
            $this->logger->info('NostrRelayPool: Local relay configured as primary', [
                'relay' => $this->nostrDefaultRelay
            ]);
        }

        // Add custom relays (excluding local relay if already added)
        if (!empty($defaultRelays)) {
            foreach ($defaultRelays as $relay) {
                if (!in_array($relay, $relayList, true)) {
                    $relayList[] = $relay;
                }
            }
        } else {
            // Use public relays as fallback
            foreach (self::PUBLIC_RELAYS as $relay) {
                if (!in_array($relay, $relayList, true)) {
                    $relayList[] = $relay;
                }
            }
        }

        $this->defaultRelays = $relayList;

        $this->logger->info('NostrRelayPool initialized with relay priority', [
            'relay_count' => count($this->defaultRelays),
            'relays' => $this->defaultRelays
        ]);
    }

    /**
     * Normalize relay URL to ensure consistency
     */
    private function normalizeRelayUrl(string $url): string
    {
        $url = trim($url);
        // Remove trailing slash for consistency
        $url = rtrim($url, '/');
        return $url;
    }

    /**
     * Get or create a relay connection
     */
    public function getRelay(string $relayUrl): Relay
    {
        // Normalize the URL to avoid duplicates (e.g., with/without trailing slash)
        $relayUrl = $this->normalizeRelayUrl($relayUrl);

        if (!isset($this->relays[$relayUrl])) {
            $this->logger->debug('Creating new relay connection', ['relay' => $relayUrl]);
            $relay = new Relay($relayUrl);
            $this->relays[$relayUrl] = $relay;
            $this->connectionAttempts[$relayUrl] = 0;
            $this->lastConnected[$relayUrl] = time();
        } else {
            // Check if connection might be stale (older than keepalive interval)
            $age = time() - ($this->lastConnected[$relayUrl] ?? 0);
            if ($age > self::KEEPALIVE_INTERVAL) {
                $this->logger->debug('Relay connection might be stale, will validate', [
                    'relay' => $relayUrl,
                    'age' => $age
                ]);
                // The nostr library handles reconnection internally, so we just update timestamp
                $this->lastConnected[$relayUrl] = time();
            }
        }

        return $this->relays[$relayUrl];
    }

    /**
     * Get multiple relay connections, prioritizing local relay (primary default relay)
     *
     * @param array $relayUrls
     * @return array<Relay>
     */
    public function getRelays(array $relayUrls): array
    {
        $relays = [];
        $localRelay = $this->nostrDefaultRelay ? $this->normalizeRelayUrl($this->nostrDefaultRelay) : null;
        $relayUrlsNormalized = array_map([$this, 'normalizeRelayUrl'], $relayUrls);

        // Always try local relay first if configured, regardless of whether it's in the requested URLs
        if ($localRelay) {
            try {
                $relays[] = $this->getRelay($localRelay);
                $this->logger->debug('Using local relay (priority)', ['relay' => $localRelay]);
            } catch (\Throwable $e) {
                $this->logger->warning('Local relay unavailable, falling back to others', [
                    'relay' => $localRelay,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Add other relays except the local relay
        foreach ($relayUrlsNormalized as $url) {
            if ($url === $localRelay) {
                continue; // Skip local relay as we already added it
            }
            try {
                $relays[] = $this->getRelay($url);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to get relay connection', [
                    'relay' => $url,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $relays;
    }

    /**
     * Send a request to multiple relays and collect responses, prioritizing default relay
     *
     * @param array $relayUrls Array of relay URLs to query
     * @param callable $messageBuilder Function that builds the message to send
     * @param int|null $timeout Optional timeout in seconds
     * @param string|null $subscriptionId Optional subscription ID to close after receiving responses
     * @return array Responses from all relays
     */
    public function sendToRelays(array $relayUrls, callable $messageBuilder, ?int $timeout = null, ?string $subscriptionId = null): array
    {
        $timeout = $timeout ?? self::CONNECTION_TIMEOUT;
        $relays = $this->getRelays($relayUrls);

        if (empty($relays)) {
            $this->logger->error('No relays available for request');
            return [];
        }

        $responses = [];
        foreach ($relays as $relay) {
            $relayResponses = [];
            try {
                $message = $messageBuilder();
                $payload = $message->generate();

                $this->logger->debug('Sending request to relay', [
                    'relay' => $relay->getUrl(),
                    'subscription_id' => $subscriptionId
                ]);

                // Ensure relay is connected
                if (!$relay->isConnected()) {
                    $relay->connect();
                }

                $client = $relay->getClient();
                $client->setTimeout(15); // 15 seconds timeout for receiving

                // Send the request
                $client->text($payload);

                // Receive and parse responses
                while ($resp = $client->receive()) {
                    // Handle PING/PONG
                    if ($resp instanceof \WebSocket\Message\Ping) {
                        $client->text((new \WebSocket\Message\Pong())->getPayload());
                        continue;
                    }

                    if (!$resp instanceof \WebSocket\Message\Text) {
                        continue;
                    }

                    $decoded = json_decode($resp->getContent());
                    if (!$decoded) {
                        $this->logger->debug('Failed to decode response from relay', [
                            'relay' => $relay->getUrl(),
                            'content' => $resp->getContent()
                        ]);
                        continue;
                    }

                    $relayResponse = \swentel\nostr\RelayResponse\RelayResponse::create($decoded);
                    $relayResponses[] = $relayResponse;

                    // Check for EOSE (End of Stored Events) or CLOSED
                    if (in_array($relayResponse->type, ['EOSE', 'CLOSED'])) {
                        $this->logger->debug('Received EOSE/CLOSED from relay', [
                            'relay' => $relay->getUrl(),
                            'type' => $relayResponse->type
                        ]);
                        break;
                    }
                }

                // Key responses by relay URL so processResponse can identify which relay they came from
                $responses[$relay->getUrl()] = $relayResponses;

                // If subscription ID provided, send CLOSE message to clean up
                if ($subscriptionId !== null) {
                    try {
                        $closeMessage = new \swentel\nostr\Message\CloseMessage($subscriptionId);
                        $client->text($closeMessage->generate());
                        $this->logger->debug('Sent CLOSE message to relay', [
                            'relay' => $relay->getUrl(),
                            'subscription_id' => $subscriptionId
                        ]);
                    } catch (\Throwable $closeError) {
                        $this->logger->warning('Failed to send CLOSE message', [
                            'relay' => $relay->getUrl(),
                            'subscription_id' => $subscriptionId,
                            'error' => $closeError->getMessage()
                        ]);
                    }
                }

                // Update last connected time on successful send
                $this->lastConnected[$relay->getUrl()] = time();

            } catch (Exception $e) {
                // If this is a timeout, treat as normal; otherwise, rethrow or handle
                if (stripos($e->getMessage(), 'timeout') !== false) {
                    $this->logger->debug('Relay timeout (normal - all events received)', [
                        'relay' => $relay->getUrl()
                    ]);
                    $responses[$relay->getUrl()] = $relayResponses;
                    $this->lastConnected[$relay->getUrl()] = time();
                } else {
                    throw $e;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Error sending to relay', [
                    'relay' => $relay->getUrl(),
                    'error' => $e->getMessage(),
                    'class' => get_class($e)
                ]);

                // Track failed attempts
                $url = $relay->getUrl();
                $this->connectionAttempts[$url] = ($this->connectionAttempts[$url] ?? 0) + 1;

                // If too many failures, remove from pool
                if ($this->connectionAttempts[$url] >= self::MAX_RETRIES) {
                    $this->logger->warning('Removing relay from pool due to repeated failures', [
                        'relay' => $url,
                        'attempts' => $this->connectionAttempts[$url]
                    ]);
                    unset($this->relays[$url]);
                }
            }
        }

        return $responses;
    }

    /**
     * Close a specific relay connection
     */
    public function closeRelay(string $relayUrl): void
    {
        if (isset($this->relays[$relayUrl])) {
            $this->logger->debug('Closing relay connection', ['relay' => $relayUrl]);
            unset($this->relays[$relayUrl]);
            unset($this->connectionAttempts[$relayUrl]);
            unset($this->lastConnected[$relayUrl]);
        }
    }

    /**
     * Close all relay connections
     */
    public function closeAll(): void
    {
        $this->logger->info('Closing all relay connections', [
            'count' => count($this->relays)
        ]);

        $this->relays = [];
        $this->connectionAttempts = [];
        $this->lastConnected = [];
    }

    /**
     * Get statistics about the connection pool
     */
    public function getStats(): array
    {
        return [
            'active_connections' => count($this->relays),
            'relays' => array_map(function($url) {
                return [
                    'url' => $url,
                    'attempts' => $this->connectionAttempts[$url] ?? 0,
                    'last_connected' => $this->lastConnected[$url] ?? null,
                    'age' => time() - ($this->lastConnected[$url] ?? time()),
                ];
            }, array_keys($this->relays))
        ];
    }

    /**
     * Cleanup stale connections (call this periodically)
     */
    public function cleanupStaleConnections(int $maxAge = 300): int
    {
        $now = time();
        $cleaned = 0;

        foreach ($this->relays as $url => $relay) {
            $age = $now - ($this->lastConnected[$url] ?? 0);
            if ($age > $maxAge) {
                $this->logger->info('Removing stale relay connection', [
                    'relay' => $url,
                    'age' => $age
                ]);
                $this->closeRelay($url);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get default relays
     */
    public function getDefaultRelays(): array
    {
        return $this->defaultRelays;
    }

    /**
     * Get local relay URL if configured
     */
    public function getLocalRelay(): ?string
    {
        return $this->nostrDefaultRelay ?: null;
    }

    /**
     * Ensure local relay is included in a list of relay URLs
     * Adds local relay at the beginning if configured and not already in the list
     */
    public function ensureLocalRelayInList(array $relayUrls): array
    {
        if (!$this->nostrDefaultRelay) {
            return $relayUrls;
        }

        $normalizedLocal = $this->normalizeRelayUrl($this->nostrDefaultRelay);
        $normalizedUrls = array_map([$this, 'normalizeRelayUrl'], $relayUrls);

        // If local relay not in list, add it at the beginning
        if (!in_array($normalizedLocal, $normalizedUrls, true)) {
            array_unshift($relayUrls, $this->nostrDefaultRelay);
            $this->logger->debug('Added local relay to relay list', [
                'local_relay' => $this->nostrDefaultRelay,
                'total_relays' => count($relayUrls)
            ]);
        }

        return $relayUrls;
    }
}
