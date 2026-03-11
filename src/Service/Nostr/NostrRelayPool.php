<?php

namespace App\Service\Nostr;

use App\Util\NostrPhp\RelaySubscriptionHandler;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\RelayResponse\RelayResponseEvent;
use swentel\nostr\Subscription\Subscription;

/**
 * Manages persistent WebSocket connections to Nostr relays
 * Keeps connections alive across multiple requests to avoid reconnection overhead
 */
class NostrRelayPool implements RelayPoolInterface
{
    /** @var array<string, Relay> Map of relay URLs to Relay instances */
    private array $relays = [];

    /** @var array<string, int> Track connection attempts for backoff */
    private array $connectionAttempts = [];

    /** @var array<string, int> Track last connection time */
    private array $lastConnected = [];

    /** @var array<string> */
    private array $defaultRelays = [];

    private const MAX_RETRIES = 3;
    private const RETRY_DELAY = 5; // seconds
    private const CONNECTION_TIMEOUT = 15; // seconds
    private const KEEPALIVE_INTERVAL = 60; // seconds

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RelayRegistry $relayRegistry,
        private readonly RelayHealthStore $healthStore,
        private readonly string $nostrDefaultRelay,
        private readonly bool $gatewayEnabled = false,
        private readonly ?RelayGatewayClient $gatewayClient = null,
        array $defaultRelays = []
    ) {
        // Build relay list from RelayRegistry (replaces hardcoded PUBLIC_RELAYS)
        if (!empty($defaultRelays)) {
            // Explicit relay list provided — use it (with local relay first)
            $relayList = [];
            if ($this->nostrDefaultRelay) {
                $relayList[] = $this->nostrDefaultRelay;
            }
            foreach ($defaultRelays as $relay) {
                if (!in_array($relay, $relayList, true)) {
                    $relayList[] = $relay;
                }
            }
            $this->defaultRelays = $relayList;
        } else {
            // Use RelayRegistry defaults: local → project → content
            $this->defaultRelays = $this->relayRegistry->getDefaultRelays();
        }

        $this->logger->debug('NostrRelayPool initialized with relay priority', [
            'relay_count' => count($this->defaultRelays),
            'relays' => $this->defaultRelays,
            'gateway_enabled' => $this->gatewayEnabled,
        ]);
    }

    /**
     * Normalize relay URL to ensure consistency
     */
    public function normalizeRelayUrl(string $url): string
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

        // Sort external relays by health score (highest first) before connecting
        $externalUrls = array_filter($relayUrlsNormalized, fn($url) => $url !== $localRelay);
        $externalUrls = $this->sortByHealthScore(array_values($externalUrls));

        foreach ($externalUrls as $url) {
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
     * Send a request to multiple relays and collect responses, prioritizing default relay.
     *
     * When the relay gateway is enabled, external relays are routed through the
     * gateway (persistent connections, NIP-42 AUTH). Local relay traffic stays direct.
     *
     * @param array $relayUrls Array of relay URLs to query
     * @param callable $messageBuilder Function that builds the message to send
     * @param int|null $timeout Optional timeout in seconds
     * @param string|null $subscriptionId Optional subscription ID to close after receiving responses
     * @param string|null $pubkey Optional user pubkey for AUTH-gated relay routing
     * @return array Responses from all relays
     */
    public function sendToRelays(array $relayUrls, callable $messageBuilder, ?int $timeout = null, ?string $subscriptionId = null, ?string $pubkey = null): array
    {
        $timeout = $timeout ?? self::CONNECTION_TIMEOUT;

        // When gateway is enabled, route external relays through it
        if ($this->gatewayEnabled && $this->gatewayClient) {
            [$localUrls, $externalUrls] = $this->partitionRelays($relayUrls);

            $results = [];

            // Local relay: direct connection (fast, no AUTH needed)
            if (!empty($localUrls)) {
                $results += $this->sendDirect($localUrls, $messageBuilder, $timeout);
            }

            // External relays: route through gateway
            if (!empty($externalUrls)) {
                $message = $messageBuilder();
                $filter = $this->extractFilterFromPayload($message->generate());

                $gatewayResult = $this->gatewayClient->query($externalUrls, $filter, $pubkey, $timeout);

                $gatewayErrors = $gatewayResult['errors'] ?? [];

                // Convert raw event arrays from the gateway into proper RelayResponseEvent
                // objects so callers receive the same shape as TweakedRequest::send().
                //
                // RelayResponseEvent expects ["EVENT", subscriptionId, eventObject] where
                // eventObject must be a \stdClass. We synthesise the subscriptionId since
                // the gateway already discards it, and cast each event array to stdClass.
                $relayResponses = [];
                foreach ($gatewayResult['events'] ?? [] as $rawEvent) {
                    if (!is_array($rawEvent)) {
                        continue;
                    }
                    // Cast to stdClass — matches what json_decode(…, false) produces
                    $eventObj = (object) array_map(
                        static fn($v) => is_array($v) ? (object) $v : $v,
                        $rawEvent,
                    );
                    // Synthesise the ["EVENT", subId, event] envelope
                    $relayResponses[] = new RelayResponseEvent(['EVENT', 'gateway', $eventObj]);
                }

                // Distribute the response set to every external relay that did not error.
                // All callers already deduplicate events by ID, so serving the same set
                // under multiple relay keys is safe and correctly attributes events to
                // each relay that contributed to the result.
                $succeededUrls = array_diff($externalUrls, array_keys($gatewayErrors));
                foreach ($succeededUrls as $url) {
                    $results[$url] = $relayResponses;
                }

                // Record errored relays with an empty response array so callers that
                // check array_keys($responses) can still see which relays were attempted.
                foreach ($gatewayErrors as $url => $error) {
                    if (!isset($results[$url])) {
                        $results[$url] = [];
                    }
                    $this->logger->debug('Gateway reported error for relay', [
                        'relay' => $url,
                        'error' => $error,
                    ]);
                }

                // If no relays succeeded but we have events, fall back to attributing
                // them to all external URLs (gateway returned events without errors).
                if (empty($succeededUrls) && !empty($relayResponses)) {
                    foreach ($externalUrls as $url) {
                        $results[$url] = $relayResponses;
                    }
                }
            }

            return $results;
        }

        // Gateway disabled: direct connection (current behavior)
        return $this->sendDirect($relayUrls, $messageBuilder, $timeout);
    }

    /**
     * Send directly to relays via TweakedRequest (bypassing gateway).
     */
    private function sendDirect(array $relayUrls, callable $messageBuilder, int $timeout): array
    {
        $relays = $this->getRelays($relayUrls);

        if (empty($relays)) {
            $this->logger->error('No relays available for request');
            return [];
        }

        // Use TweakedRequest for proper NIP-42 AUTH, PING/PONG, and CLOSE handling
        $message = $messageBuilder();
        $relaySet = new \swentel\nostr\Relay\RelaySet();
        $relaySet->setRelays($relays);

        $request = new \App\Util\NostrPhp\TweakedRequest($relaySet, $message, $this->logger);
        $request->setTimeout($timeout);

        $this->logger->info('Sending request to relays via TweakedRequest', [
            'relay_count' => count($relays),
            'relays' => array_map(fn($r) => $r->getUrl(), $relays),
        ]);

        try {
            $startTime = microtime(true);
            $responses = $request->send();
            $elapsedMs = (int) ((microtime(true) - $startTime) * 1000);

            // Update last connected time and health for all relays that returned responses
            foreach ($relays as $relay) {
                $url = $relay->getUrl();
                if (isset($responses[$url])) {
                    $this->lastConnected[$url] = time();
                    // Reset connection attempts on success
                    $this->connectionAttempts[$url] = 0;
                    // Record success in persistent health store
                    $this->healthStore->recordSuccess($url, $elapsedMs);
                }
            }

            return $responses;
        } catch (\Throwable $e) {
            $this->logger->error('Error in sendToRelays via TweakedRequest', [
                'error' => $e->getMessage(),
                'class' => get_class($e)
            ]);

            // Track failed attempts for all relays
            foreach ($relays as $relay) {
                $url = $relay->getUrl();
                $this->connectionAttempts[$url] = ($this->connectionAttempts[$url] ?? 0) + 1;
                // Record failure in persistent health store
                $this->healthStore->recordFailure($url);

                // If too many failures, remove from pool
                if ($this->connectionAttempts[$url] >= self::MAX_RETRIES) {
                    $this->logger->warning('Removing relay from pool due to repeated failures', [
                        'relay' => $url,
                        'attempts' => $this->connectionAttempts[$url]
                    ]);
                    unset($this->relays[$url]);
                }
            }

            return [];
        }
    }

    /**
     * Partition relay URLs into local and external groups.
     *
     * @return array{0: string[], 1: string[]} [localUrls, externalUrls]
     */
    private function partitionRelays(array $relayUrls): array
    {
        $localRelay   = $this->nostrDefaultRelay ? $this->normalizeRelayUrl($this->nostrDefaultRelay) : null;
        // PROJECT is the public wss:// alias of the same strfry instance as LOCAL.
        // Treat it as local so it's never sent through the gateway.
        $projectRelay = ($pr = $this->relayRegistry->getProjectRelay()) ? $this->normalizeRelayUrl($pr) : null;

        $local    = [];
        $external = [];

        foreach ($relayUrls as $url) {
            $normalized = $this->normalizeRelayUrl($url);
            if (($localRelay && $normalized === $localRelay)
                || ($projectRelay && $normalized === $projectRelay)) {
                $local[] = $url;
            } else {
                $external[] = $url;
            }
        }

        return [$local, $external];
    }

    /**
     * Best-effort extraction of filter parameters from a generated REQ payload.
     * The gateway needs the filter as an associative array.
     */
    private function extractFilterFromPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        // REQ format: ["REQ", subscriptionId, filter1, filter2, ...]
        if (is_array($decoded) && ($decoded[0] ?? '') === 'REQ' && isset($decoded[2])) {
            return $decoded[2];
        }
        return [];
    }

    /**
     * Publish a signed Nostr event to a list of relay URLs.
     *
     * When the gateway is enabled, external (AUTH-protected) relays are routed
     * through the gateway so NIP-42 AUTH challenges are handled automatically.
     * The local relay always receives the event via a direct RelaySet::send().
     *
     * @param \swentel\nostr\Event\Event $event   Signed event to publish
     * @param array                      $relayUrls Target relay URLs
     * @param ?string                    $pubkey  Author pubkey (used by gateway for AUTH)
     * @param int                        $timeout Seconds to wait per relay
     * @return array Results keyed by relay URL
     */
    public function publish(\swentel\nostr\Event\Event $event, array $relayUrls, ?string $pubkey = null, int $timeout = 30): array
    {
        if ($this->gatewayEnabled && $this->gatewayClient) {
            [$localUrls, $externalUrls] = $this->partitionRelays($relayUrls);

            $results = [];

            // Local relay — direct, no AUTH needed
            if (!empty($localUrls)) {
                $results = array_merge($results, $this->publishDirect($event, $localUrls, $timeout));
            }

            // External relays — route through gateway (handles NIP-42 AUTH)
            if (!empty($externalUrls)) {
                $signedEvent = $event->toArray();
                $gatewayResult = $this->gatewayClient->publish($externalUrls, $signedEvent, $pubkey, $timeout);

                foreach ($externalUrls as $url) {
                    $results[$url] = isset($gatewayResult['errors'][$url])
                        ? ['ok' => false, 'message' => $gatewayResult['errors'][$url]]
                        : ['ok' => $gatewayResult['ok'][$url] ?? true];
                }

                $this->logger->info('Published event to external relays via gateway', [
                    'event_id'   => $event->getId(),
                    'ok_count'   => count($gatewayResult['ok'] ?? []),
                    'error_count' => count($gatewayResult['errors'] ?? []),
                ]);
            }

            return $results;
        }

        // Gateway disabled — direct publish to all relays
        return $this->publishDirect($event, $relayUrls, $timeout);
    }

    /**
     * Publish directly to relays via swentel RelaySet::send() (no NIP-42 AUTH).
     * Only safe for local/non-AUTH relays.
     */
    private function publishDirect(\swentel\nostr\Event\Event $event, array $relayUrls, int $timeout): array
    {
        $eventMessage = new \swentel\nostr\Message\EventMessage($event);

        $relaySet = new \swentel\nostr\Relay\RelaySet();
        foreach ($relayUrls as $url) {
            $relay = $this->getRelay($url);
            try {
                $client = $relay->getClient();
                if (method_exists($client, 'setTimeout')) {
                    $client->setTimeout($timeout);
                }
            } catch (\Exception) {}
            $relaySet->addRelay($relay);
        }
        $relaySet->setMessage($eventMessage);

        try {
            return $relaySet->send();
        } catch (\Exception $e) {
            $this->logger->error('Direct publish failed', [
                'error'  => $e->getMessage(),
                'relays' => $relayUrls,
            ]);
            return [];
        }
    }

    /**
     * Check if the relay gateway is enabled and the client is available.
     */
    public function isGatewayEnabled(): bool
    {
        return $this->gatewayEnabled && $this->gatewayClient !== null;
    }

    /**
     * Get the gateway client (for callers that need direct gateway access).
     */
    public function getGatewayClient(): ?RelayGatewayClient
    {
        return $this->gatewayClient;
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
     * Get the RelayRegistry instance (for callers that need purpose-based relay resolution).
     */
    public function getRelayRegistry(): RelayRegistry
    {
        return $this->relayRegistry;
    }

    /**
     * Get the RelayHealthStore instance (for callers that need health data).
     */
    public function getHealthStore(): RelayHealthStore
    {
        return $this->healthStore;
    }

    /**
     * Sort relay URLs by health score (highest first).
     *
     * Used by getRelays() to prioritize healthier external relays.
     * The local relay is kept at index 0 by the caller and never passed here.
     *
     * @param string[] $urls
     * @return string[]
     */
    private function sortByHealthScore(array $urls): array
    {
        usort($urls, function (string $a, string $b): int {
            $scoreA = $this->healthStore->getHealthScore($a);
            $scoreB = $this->healthStore->getHealthScore($b);
            // Descending: higher score first
            return $scoreB <=> $scoreA;
        });
        return $urls;
    }

    /**
     * Unified subscription loop for local relay events.
     *
     * Replaces the three near-identical methods (subscribeLocalArticles,
     * subscribeLocalMedia, subscribeLocalGenericEvents) with one parameterized
     * implementation that uses RelaySubscriptionHandler consistently.
     *
     * @param array<int> $kinds     Event kinds to subscribe to
     * @param callable   $onEvent   Callback: function(object $event, string $relayUrl): void
     * @param string     $workerName  Label for heartbeat tracking (e.g. 'articles', 'media')
     * @throws \Exception If local relay is not configured
     */
    public function subscribeLocal(array $kinds, callable $onEvent, string $workerName = 'generic'): void
    {
        if (!$this->nostrDefaultRelay) {
            throw new \Exception('Local relay not configured. Set NOSTR_DEFAULT_RELAY environment variable.');
        }

        if (empty($kinds)) {
            throw new \InvalidArgumentException('At least one event kind must be specified');
        }

        $relayUrl = $this->normalizeRelayUrl($this->nostrDefaultRelay);

        $this->logger->info('Starting long-lived subscription to local relay', [
            'relay' => $relayUrl,
            'kinds' => $kinds,
            'worker' => $workerName,
        ]);

        // Get relay connection
        $relay = $this->getRelay($relayUrl);

        if (!$relay->isConnected()) {
            $relay->connect();
        }

        $client = $relay->getClient();
        $client->setTimeout(15);

        // Create subscription
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();

        $filter = new Filter();
        $filter->setKinds($kinds);

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $payload = $requestMessage->generate();

        $this->logger->info('Sending REQ to local relay', [
            'relay' => $relayUrl,
            'subscription_id' => $subscriptionId,
            'filter' => ['kinds' => $kinds],
            'worker' => $workerName,
        ]);

        $client->text($payload);

        $this->logger->info('Entering infinite receive loop...', ['worker' => $workerName]);

        $eoseReceived = false;
        $lastTimeoutLog = 0;

        // Use shared handler for all relay protocol logic
        $handler = new RelaySubscriptionHandler($this->logger);

        while (true) {
            try {
                $resp = $client->receive();

                // PING/PONG
                if ($resp instanceof \WebSocket\Message\Ping) {
                    $handler->handlePing($client);
                    continue;
                }

                if (!($resp instanceof \WebSocket\Message\Text)) {
                    continue;
                }

                $relayResponse = $handler->parseRelayResponse($resp);
                if (!$relayResponse) {
                    continue;
                }

                switch ($relayResponse->type) {
                    case 'EVENT':
                        if ($relayResponse instanceof \swentel\nostr\RelayResponse\RelayResponseEvent) {
                            $event = $relayResponse->event;

                            $this->logger->info('Received event from relay', [
                                'relay' => $relayUrl,
                                'event_id' => substr($event->id ?? 'unknown', 0, 16) . '...',
                                'kind' => $event->kind ?? 'unknown',
                                'pubkey' => substr($event->pubkey ?? 'unknown', 0, 16) . '...',
                                'worker' => $workerName,
                            ]);

                            // Record event in health store
                            $this->healthStore->recordEventReceived($relayUrl);

                            try {
                                $onEvent($event, $relayUrl);
                            } catch (\Throwable $e) {
                                $this->logger->error('Error in event callback', [
                                    'event_id' => $event->id ?? 'unknown',
                                    'kind' => $event->kind ?? 'unknown',
                                    'error' => $e->getMessage(),
                                    'exception' => get_class($e),
                                    'worker' => $workerName,
                                ]);
                            }
                        }
                        break;

                    case 'EOSE':
                        $eoseReceived = true;
                        $this->logger->info('Received EOSE — stored events processed, listening for new events', [
                            'relay' => $relayUrl,
                            'subscription_id' => $subscriptionId,
                            'worker' => $workerName,
                        ]);
                        // Record successful subscription in health store
                        $this->healthStore->recordSuccess($relayUrl);
                        break;

                    case 'CLOSED':
                        $message = $handler->extractMessage(json_decode($resp->getContent()));
                        $this->logger->warning('Relay closed subscription', [
                            'relay' => $relayUrl,
                            'subscription_id' => $subscriptionId,
                            'message' => $message,
                            'worker' => $workerName,
                        ]);
                        throw new \Exception('Subscription closed by relay: ' . $message);

                    case 'NOTICE':
                        $message = $handler->extractMessage(json_decode($resp->getContent()));
                        if ($handler->isErrorNotice($message)) {
                            $this->logger->warning('Received ERROR NOTICE from relay', [
                                'relay' => $relayUrl,
                                'message' => $message,
                                'worker' => $workerName,
                            ]);
                            throw new \Exception('Relay error: ' . $message);
                        }
                        $this->logger->info('Received NOTICE from relay', [
                            'relay' => $relayUrl,
                            'message' => $message,
                        ]);
                        break;

                    case 'OK':
                        $this->logger->debug('Received OK from relay', ['relay' => $relayUrl]);
                        break;

                    case 'AUTH':
                        $challenge = $handler->extractAuthChallenge(json_decode($resp->getContent()));
                        if ($challenge) {
                            $this->logger->debug('Received AUTH challenge, responding', [
                                'relay' => $relayUrl,
                                'worker' => $workerName,
                            ]);
                            $handler->handleAuth($relay, $client, $challenge);
                            // Record that this relay requires AUTH
                            $this->healthStore->setAuthRequired($relayUrl);
                            $this->healthStore->setAuthStatus($relayUrl, 'ephemeral');
                            // Re-send subscription after AUTH
                            $client->text($payload);
                        }
                        break;

                    default:
                        $this->logger->debug('Received unknown message type from relay', [
                            'relay' => $relayUrl,
                            'type' => $relayResponse->type ?? 'unknown',
                        ]);
                        break;
                }

                // Write heartbeat after processing any message
                $this->healthStore->recordHeartbeat($relayUrl, $workerName);

            } catch (\Throwable $e) {
                if ($handler->isTimeoutError($e)) {
                    if ($eoseReceived) {
                        if (time() - $lastTimeoutLog > 60) {
                            $this->logger->debug('Waiting for new events (no activity)', [
                                'relay' => $relayUrl,
                                'kinds' => $kinds,
                                'worker' => $workerName,
                            ]);
                            $lastTimeoutLog = time();
                        }
                        // Write heartbeat even on timeout to show the worker is alive
                        $this->healthStore->recordHeartbeat($relayUrl, $workerName);
                    }
                    continue;
                }

                if ($handler->isBadMessageError($e)) {
                    $this->logger->debug('Skipping invalid message from relay, continuing', [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                // Fatal error — record and rethrow for Docker restart
                $this->healthStore->recordFailure($relayUrl);
                $this->logger->error('Fatal error in subscription loop', [
                    'relay' => $relayUrl,
                    'kinds' => $kinds,
                    'worker' => $workerName,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                throw $e;
            }
        }
    }

    /**
     * Subscribe to local relay for article events (kind 30023).
     * Thin wrapper around subscribeLocal().
     *
     * @param callable $onArticleEvent Callback: function(object $event, string $relayUrl): void
     */
    public function subscribeLocalArticles(callable $onArticleEvent): void
    {
        $this->subscribeLocal([30023], $onArticleEvent, 'articles');
    }

    /**
     * Subscribe to local relay for media events (kinds 20, 21, 22).
     * Thin wrapper around subscribeLocal().
     *
     * @param callable $onMediaEvent Callback: function(object $event, string $relayUrl): void
     */
    public function subscribeLocalMedia(callable $onMediaEvent): void
    {
        $this->subscribeLocal([20, 21, 22], $onMediaEvent, 'media');
    }

    /**
     * Subscribe to local relay for generic events by kind(s).
     * Thin wrapper around subscribeLocal().
     *
     * @param array<int> $kinds Event kinds to subscribe to
     * @param callable $onEvent Callback: function(object $event, string $relayUrl): void
     */
    public function subscribeLocalGenericEvents(array $kinds, callable $onEvent): void
    {
        $this->subscribeLocal($kinds, $onEvent, 'generic');
    }
}
