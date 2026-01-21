<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\RelayResponse\RelayResponse;
use swentel\nostr\Subscription\Subscription;

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

                    $relayResponse = RelayResponse::create($decoded);
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

            } catch (\Exception $e) {
                // If this is a timeout, treat as normal (all events received)
                if (stripos($e->getMessage(), 'timeout') !== false) {
                    $this->logger->debug('Relay timeout (normal - all events received)', [
                        'relay' => $relay->getUrl()
                    ]);
                    $responses[$relay->getUrl()] = $relayResponses;
                    $this->lastConnected[$relay->getUrl()] = time();
                } else {
                    // Log the error but continue with other relays instead of throwing
                    $this->logger->error('Error sending to relay (continuing with others)', [
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

    /**
     * Subscribe to local relay for article events with long-lived connection
     * This method blocks indefinitely and calls the callback for each received article event
     *
     * @param callable $onArticleEvent Callback function that receives (object $event, string $relayUrl)
     * @throws \Exception|\Throwable If local relay is not configured or connection fails
     */
    public function subscribeLocalArticles(callable $onArticleEvent): void
    {
        if (!$this->nostrDefaultRelay) {
            throw new \Exception('Local relay not configured. Set NOSTR_DEFAULT_RELAY environment variable.');
        }

        $relayUrl = $this->normalizeRelayUrl($this->nostrDefaultRelay);

        $this->logger->info('Starting long-lived subscription to local relay for articles', [
            'relay' => $relayUrl,
            'kind' => 30023
        ]);

        // Get relay connection
        $relay = $this->getRelay($relayUrl);

        // Ensure relay is connected
        if (!$relay->isConnected()) {
            $relay->connect();
        }

        $client = $relay->getClient();

        // Use reasonable timeout like TweakedRequest does (15 seconds per receive call)
        // This allows for proper WebSocket handshake and message handling
        $client->setTimeout(15);

        // Create subscription for article events (kind 30023)
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();

        $filter = new Filter();
        $filter->setKinds([30023, 20]); // Longform article events, images

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $payload = $requestMessage->generate();

        $this->logger->info('Sending REQ to local relay', [
            'relay' => $relayUrl,
            'subscription_id' => $subscriptionId,
            'filter' => ['kinds' => [30023, 20]]
        ]);

        // Send the subscription request
        $client->text($payload);

        $this->logger->info('Entering infinite receive loop for article events...');

        // Track if we've received EOSE
        $eoseReceived = false;

        // Infinite loop: receive and process messages
        while (true) {
            try {
                $resp = $client->receive();

                // Handle PING/PONG for keepalive (following TweakedRequest pattern)
                if ($resp instanceof \WebSocket\Message\Ping) {
                    $client->text((new \WebSocket\Message\Pong())->getPayload());
                    $this->logger->debug('Received PING, sent PONG');
                    continue;
                }

                // Only process text messages
                if (!($resp instanceof \WebSocket\Message\Text)) {
                    continue;
                }

                $content = $resp->getContent();
                $decoded = json_decode($content); // Decode as object for RelayResponse compatibility

                if (!$decoded) {
                    $this->logger->debug('Failed to decode message from relay', [
                        'relay' => $relayUrl,
                        'content_preview' => substr($content, 0, 100)
                    ]);
                    continue;
                }

                // Parse relay response
                $relayResponse = RelayResponse::create($decoded);

                // Handle different response types
                switch ($relayResponse->type) {
                    case 'EVENT':
                        // This is an article event - process it
                        if ($relayResponse instanceof \swentel\nostr\RelayResponse\RelayResponseEvent) {
                            $event = $relayResponse->event;

                            $this->logger->info('Received article event from relay', [
                                'relay' => $relayUrl,
                                'event_id' => $event->id ?? 'unknown',
                                'kind' => $event->kind ?? 'unknown',
                                'pubkey' => substr($event->pubkey ?? 'unknown', 0, 16) . '...'
                            ]);

                            // Call the callback with the event
                            if ($event->kind === 30023) {
                                try {
                                    $onArticleEvent($event, $relayUrl);
                                } catch (\Throwable $e) {
                                    // Log callback errors but don't break the loop
                                    $this->logger->error('Error in article event callback', [
                                        'event_id' => $event->id ?? 'unknown',
                                        'error' => $e->getMessage(),
                                        'exception' => get_class($e)
                                    ]);
                                }
                            } else {
                                $this->logger->error('Got media in callback', [
                                    'event_id' => $event->id ?? 'unknown'
                                ]);
                            }
                        }
                        break;

                    case 'EOSE':
                        // End of stored events - all historical events received
                        // Unlike TweakedRequest, we DON'T close the subscription or disconnect
                        // We want to keep listening for new live events
                        $eoseReceived = true;
                        $this->logger->info('Received EOSE - all stored events processed, now listening for new events', [
                            'relay' => $relayUrl,
                            'subscription_id' => $subscriptionId
                        ]);
                        // Continue the loop - this is the key difference from short-lived requests
                        break;

                    case 'CLOSED':
                        // Subscription closed by relay
                        // Decode as array to access message by index
                        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
                        $this->logger->warning('Relay closed subscription', [
                            'relay' => $relayUrl,
                            'subscription_id' => $subscriptionId,
                            'message' => $decodedArray[2] ?? 'no message'
                        ]);
                        throw new \Exception('Subscription closed by relay: ' . ($decodedArray[2] ?? 'no message'));

                    case 'NOTICE':
                        // Notice from relay - check if it's an error
                        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
                        $message = $decodedArray[1] ?? 'no message';
                        if (str_starts_with($message, 'ERROR:')) {
                            $this->logger->error('Received ERROR NOTICE from relay', [
                                'relay' => $relayUrl,
                                'message' => $message
                            ]);
                            throw new \Exception('Relay error: ' . $message);
                        }
                        $this->logger->info('Received NOTICE from relay', [
                            'relay' => $relayUrl,
                            'message' => $message
                        ]);
                        break;

                    case 'OK':
                        // Command result (usually for EVENT, not REQ)
                        $this->logger->debug('Received OK from relay', [
                            'relay' => $relayUrl,
                            'message' => json_encode($decoded)
                        ]);
                        break;

                    case 'AUTH':
                        // NIP-42 auth challenge - log but don't handle for now
                        // Most relays won't require auth for reading public events
                        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
                        $this->logger->info('Received AUTH challenge from relay (ignoring for read-only subscription)', [
                            'relay' => $relayUrl,
                            'challenge' => $decodedArray[1] ?? 'no challenge'
                        ]);
                        break;

                    default:
                        $this->logger->debug('Received unknown message type from relay', [
                            'relay' => $relayUrl,
                            'type' => $relayResponse->type ?? 'unknown',
                            'content' => json_encode($decoded)
                        ]);
                        break;
                }

            } catch (\Throwable $e) {
                $errorMessage = strtolower($e->getMessage());
                $errorClass = strtolower(get_class($e));

                // Timeouts and connection errors are normal when waiting for new events - just continue
                if (stripos($errorMessage, 'timeout') !== false ||
                    stripos($errorClass, 'timeout') !== false ||
                    stripos($errorMessage, 'connection operation') !== false) {
                    if ($eoseReceived) {
                        // Only log timeout every 60 seconds to avoid spam
                        static $lastArticleTimeoutLog = 0;
                        if (time() - $lastArticleTimeoutLog > 60) {
                            $this->logger->debug('Waiting for new article events (no activity)', [
                                'relay' => $relayUrl
                            ]);
                            $lastArticleTimeoutLog = time();
                        }
                    }
                    continue;
                }

                // Unparseable messages and bad msg errors - log and continue
                if (stripos($errorMessage, 'bad msg') !== false ||
                    stripos($errorMessage, 'unparseable') !== false ||
                    stripos($errorMessage, 'invalid') !== false) {
                    $this->logger->debug('Skipping invalid message from relay, continuing', [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }

                // Fatal errors - log and rethrow to allow Docker restart
                $this->logger->error('Fatal error in subscription loop', [
                    'relay' => $relayUrl,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ]);
                throw $e;
            }
        }

        // This line should never be reached (infinite loop above)
        // But if it is, log it
        $this->logger->warning('Subscription loop exited unexpectedly');
    }

    /**
     * Subscribe to local relay for media events (kinds 20, 21, 22) in real-time
     * This is a long-lived subscription that processes events via callback
     * Blocks forever - meant to be run as a daemon process
     *
     * @param callable $onMediaEvent Callback function(object $event, string $relayUrl): void
     * @throws \Exception if subscription fails
     */
    public function subscribeLocalMedia(callable $onMediaEvent): void
    {
        if (!$this->nostrDefaultRelay) {
            throw new \Exception('Local relay not configured. Set NOSTR_DEFAULT_RELAY environment variable.');
        }

        $relayUrl = $this->normalizeRelayUrl($this->nostrDefaultRelay);

        $this->logger->info('Starting long-lived subscription to local relay for media events', [
            'relay' => $relayUrl,
            'kinds' => [20, 21, 22]
        ]);

        // Get relay connection
        $relay = $this->getRelay($relayUrl);

        // Ensure relay is connected
        if (!$relay->isConnected()) {
            $relay->connect();
        }

        $client = $relay->getClient();

        // Use reasonable timeout (15 seconds per receive call)
        $client->setTimeout(15);

        // Create subscription for media events (kinds 20, 21, 22)
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();

        $filter = new Filter();
        $filter->setKinds([20]); // NIP-68 Picture and Video events

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $payload = $requestMessage->generate();

        $this->logger->info('Sending REQ to local relay for media', [
            'relay' => $relayUrl,
            'subscription_id' => $subscriptionId,
            'filter' => ['kinds' => [20]]
        ]);

        // Send the subscription request
        $client->text($payload);

        $this->logger->info('Entering infinite receive loop for media events...');

        // Track if we've received EOSE
        $eoseReceived = false;

        // Infinite loop: receive and process messages
        while (true) {
            try {
                $resp = $client->receive();

                // Handle PING/PONG for keepalive
                if ($resp instanceof \WebSocket\Message\Ping) {
                    $client->text((new \WebSocket\Message\Pong())->getPayload());
                    $this->logger->debug('Received PING, sent PONG');
                    continue;
                }

                // Only process text messages
                if (!($resp instanceof \WebSocket\Message\Text)) {
                    continue;
                }

                $content = $resp->getContent();
                $decoded = json_decode($content);

                if (!$decoded) {
                    $this->logger->debug('Failed to decode message from relay', [
                        'relay' => $relayUrl,
                        'content_preview' => substr($content, 0, 100)
                    ]);
                    continue;
                }

                // Parse relay response
                $relayResponse = RelayResponse::create($decoded);

                // Handle different response types
                switch ($relayResponse->type) {
                    case 'EVENT':
                        // This is a media event - process it
                        if ($relayResponse instanceof \swentel\nostr\RelayResponse\RelayResponseEvent) {
                            $event = $relayResponse->event;

                            $this->logger->info('Received media event from relay', [
                                'relay' => $relayUrl,
                                'event_id' => substr($event->id ?? 'unknown', 0, 16) . '...',
                                'kind' => $event->kind ?? 'unknown',
                                'pubkey' => substr($event->pubkey ?? 'unknown', 0, 16) . '...'
                            ]);

                            // Call the callback with the event
                            try {
                                $onMediaEvent($event, $relayUrl);
                            } catch (\Throwable $e) {
                                // Log callback errors but don't break the loop
                                $this->logger->error('Error in media event callback', [
                                    'event_id' => $event->id ?? 'unknown',
                                    'error' => $e->getMessage(),
                                    'exception' => get_class($e)
                                ]);
                            }
                        }
                        break;

                    case 'EOSE':
                        // End of stored events - keep listening for new events
                        $eoseReceived = true;
                        $this->logger->info('Received EOSE - all stored media events processed, now listening for new events', [
                            'relay' => $relayUrl,
                            'subscription_id' => $subscriptionId
                        ]);
                        break;

                    case 'CLOSED':
                        // Subscription closed by relay
                        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
                        $this->logger->warning('Relay closed subscription', [
                            'relay' => $relayUrl,
                            'subscription_id' => $subscriptionId,
                            'message' => $decodedArray[2] ?? 'no message'
                        ]);
                        throw new \Exception('Subscription closed by relay: ' . ($decodedArray[2] ?? 'no message'));

                    case 'NOTICE':
                        // Notice from relay
                        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
                        $message = $decodedArray[1] ?? 'no message';
                        if (str_starts_with($message, 'ERROR:')) {
                            $this->logger->error('Received ERROR NOTICE from relay', [
                                'relay' => $relayUrl,
                                'message' => $message
                            ]);
                            throw new \Exception('Relay error: ' . $message);
                        }
                        $this->logger->info('Received NOTICE from relay', [
                            'relay' => $relayUrl,
                            'message' => $message
                        ]);
                        break;

                    case 'OK':
                        // OK response - just log it
                        $this->logger->debug('Received OK from relay', ['relay' => $relayUrl]);
                        break;

                    default:
                        $this->logger->debug('Unknown relay response type', [
                            'relay' => $relayUrl,
                            'type' => $relayResponse->type
                        ]);
                        break;
                }

            } catch (\Throwable $e) {
                $errorMessage = strtolower($e->getMessage());
                $errorClass = strtolower(get_class($e));

                // Timeouts and connection errors are normal when waiting for new events - just continue
                if (stripos($errorMessage, 'timeout') !== false ||
                    stripos($errorClass, 'timeout') !== false ||
                    stripos($errorMessage, 'connection operation') !== false) {
                    if ($eoseReceived) {
                        // Only log timeout every 60 seconds to avoid spam
                        static $lastTimeoutLog = 0;
                        if (time() - $lastTimeoutLog > 60) {
                            $this->logger->debug('Waiting for new media events (no activity)', [
                                'relay' => $relayUrl
                            ]);
                            $lastTimeoutLog = time();
                        }
                    }
                    continue;
                }

                // Unparseable messages and bad msg errors - log and continue
                if (stripos($errorMessage, 'bad msg') !== false ||
                    stripos($errorMessage, 'unparseable') !== false ||
                    stripos($errorMessage, 'invalid') !== false) {
                    $this->logger->debug('Skipping invalid message from relay, continuing', [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }

                // Fatal errors - log and rethrow to allow Docker restart
                $this->logger->error('Fatal error in media subscription loop', [
                    'relay' => $relayUrl,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e)
                ]);
                throw $e;
            }
        }

        // This line should never be reached (infinite loop above)
        $this->logger->warning('Media subscription loop exited unexpectedly');
    }
}
