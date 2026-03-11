<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Client-side interface to the relay gateway process.
 *
 *
 * Injected into FrankenPHP request workers. Communicates with the long-lived
 * RelayGatewayCommand via Redis Streams:
 *
 *   relay:requests          — query / publish requests (XADD, then XREAD BLOCK for response)
 *   relay:control           — lifecycle commands: warm, close (fire-and-forget)
 *   relay:responses:{id}    — per-correlation-ID response stream (auto-expires)
 *
 * When the gateway is disabled (RELAY_GATEWAY_ENABLED=false), callers should
 * fall back to direct WebSocket connections via TweakedRequest.
 */
class RelayGatewayClient
{
    private const REQUEST_STREAM = 'relay:requests';
    private const CONTROL_STREAM = 'relay:control';
    private const RESPONSE_PREFIX = 'relay:responses:';
    private const RESPONSE_TTL = 60; // seconds

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Send a filter to relays via the gateway and wait for results.
     *
     * @param string[] $relayUrls Relay URLs to query
     * @param array    $filter    Nostr filter (kinds, authors, limit, etc.)
     * @param ?string  $pubkey    User pubkey — gateway uses user connection if available
     * @param int      $timeout   Max wait time in seconds
     * @return array{events: array, errors: array<string, string>}
     */
    public function query(array $relayUrls, array $filter, ?string $pubkey = null, int $timeout = 15): array
    {
        $correlationId = Uuid::v4()->toRfc4122();

        $this->logger->debug('RelayGatewayClient: sending query', [
            'correlation_id' => $correlationId,
            'relays' => $relayUrls,
            'filter_kinds' => $filter['kinds'] ?? [],
            'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
            'timeout' => $timeout,
        ]);

        try {
            // Publish request to the gateway input stream
            $this->redis->xAdd(self::REQUEST_STREAM, '*', [
                'id' => $correlationId,
                'action' => 'query',
                'relays' => json_encode($relayUrls),
                'filter' => json_encode($filter),
                'pubkey' => $pubkey ?? '',
                'timeout' => (string) $timeout,
            ]);

            // Block-read from the per-request response stream.
            // Start from '0-0' so we never miss a response that the gateway
            // wrote before we began reading (race-free). After the first batch
            // we advance $lastId to the highest entry ID seen, preventing
            // duplicate processing on subsequent loop iterations.
            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            $deadline = time() + $timeout + 2; // +2s grace for gateway processing
            $events = [];
            $errors = [];
            $eose = false;
            $lastId = '0-0'; // always start from the beginning of this unique stream

            while (!$eose && time() < $deadline) {
                // Cap each xRead block at 1s — see publish() for rationale.
                $remainingMs = min(1000, max(100, ($deadline - time()) * 1000));
                $result = $this->redis->xRead(
                    [$responseKey => $lastId],
                    100,
                    (int) $remainingMs,
                );

                if ($result && isset($result[$responseKey])) {
                    foreach ($result[$responseKey] as $messageId => $data) {
                        // Advance the cursor so the next iteration only reads
                        // messages that arrive after this one.
                        $lastId = $messageId;

                        if (isset($data['events'])) {
                            $decoded = json_decode($data['events'], true);
                            if (is_array($decoded)) {
                                $events = array_merge($events, $decoded);
                            }
                        }
                        if (isset($data['errors'])) {
                            $decoded = json_decode($data['errors'], true);
                            if (is_array($decoded)) {
                                $errors = array_merge($errors, $decoded);
                            }
                        }
                        if (($data['eose'] ?? '') === 'true') {
                            $eose = true;
                        }
                    }
                }
                // xRead returns false/empty on timeout — loop again if deadline
                // not yet reached (gateway may still be fetching).
            }

            // Clean up the response stream
            try {
                $this->redis->expire($responseKey, self::RESPONSE_TTL);
            } catch (\RedisException) {
                // Ignore cleanup failures
            }

            $this->logger->debug('RelayGatewayClient: query complete', [
                'correlation_id' => $correlationId,
                'event_count' => count($events),
                'eose' => $eose,
                'errors' => !empty($errors) ? $errors : null,
            ]);

            return ['events' => $events, 'errors' => $errors];

        } catch (\RedisException $e) {
            $this->logger->error('RelayGatewayClient: Redis error during query', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
            return ['events' => [], 'errors' => ['gateway' => $e->getMessage()]];
        }
    }

    /**
     * Publish a signed event to relays via the gateway.
     *
     * The gateway writes one stream entry per relay as soon as that relay
     * resolves (OK received, timed out, or connection failed). This method
     * reads them as they arrive, so a slow or stalled relay does not delay
     * the result for relays that responded quickly.
     *
     * Stream entry shapes:
     *
     *   Header (first entry):    { total: "N" }
     *   Per-relay partial:       { relay: url, ok: "true"|"false", message: "...", done: "true"|"false" }
     *   Fast-path (0 relays):    { ok: <json>, errors: <json>, done: "true" }
     *
     * @param string[] $relayUrls   Relay URLs to publish to
     * @param array    $signedEvent Signed Nostr event (as associative array)
     * @param ?string  $pubkey      User pubkey for AUTH-gated relays
     * @return array{ok: array<string, bool>, errors: array<string, string>}
     */
    public function publish(array $relayUrls, array $signedEvent, ?string $pubkey = null, int $timeout = 10): array
    {
        $correlationId = Uuid::v4()->toRfc4122();

        $this->logger->debug('RelayGatewayClient: publishing event', [
            'correlation_id' => $correlationId,
            'relays'         => $relayUrls,
            'event_id'       => substr($signedEvent['id'] ?? 'unknown', 0, 16),
            'pubkey'         => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
        ]);

        try {
            $this->redis->xAdd(self::REQUEST_STREAM, '*', [
                'id'      => $correlationId,
                'action'  => 'publish',
                'relays'  => json_encode($relayUrls),
                'event'   => json_encode($signedEvent),
                'pubkey'  => $pubkey ?? '',
                'timeout' => (string) $timeout,
            ]);

            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            // +3s grace: AUTH settle (1s) + gateway event-loop tick + network RTT
            $deadline    = time() + $timeout + 3;
            $ok          = [];
            $errors      = [];
            $lastId      = '0-0';
            $totalExpected = count($relayUrls); // client-side expectation
            $resolved    = 0;
            $allDone     = false;

            while (!$allDone && time() < $deadline) {
                // Cap each xRead block at 1s so we never consume large chunks of
                // PHP execution time in a single blocking call. The loop deadline
                // handles the overall timeout independently.
                $remainingMs = min(1000, max(100, ($deadline - time()) * 1000));
                $result = $this->redis->xRead(
                    [$responseKey => $lastId],
                    20,                  // read up to 20 entries per tick
                    (int) $remainingMs,
                );

                if (!$result || !isset($result[$responseKey])) {
                    continue;
                }

                foreach ($result[$responseKey] as $messageId => $data) {
                    $lastId = $messageId;

                    if (isset($data['total'])) {
                        // Header entry — override our local count with what the gateway says
                        $totalExpected = (int) $data['total'];
                        continue;
                    }

                    if (isset($data['relay'])) {
                        // Per-relay partial result
                        $relayUrl = $data['relay'];
                        $accepted = ($data['ok'] ?? 'false') === 'true';
                        $message  = $data['message'] ?? '';
                        $ok[$relayUrl] = $accepted;
                        if (!$accepted && $message !== '') {
                            $errors[$relayUrl] = $message;
                        }
                        $resolved++;
                    } elseif (isset($data['ok'])) {
                        // Legacy / zero-relay fast-path: bulk response
                        $decoded = json_decode($data['ok'], true);
                        if (is_array($decoded)) {
                            $ok = array_merge($ok, $decoded);
                        }
                        $decoded = json_decode($data['errors'] ?? '{}', true);
                        if (is_array($decoded)) {
                            $errors = array_merge($errors, $decoded);
                        }
                    }

                    if (($data['done'] ?? '') === 'true') {
                        $allDone = true;
                        break;
                    }

                    // Stop early if we have results for every expected relay
                    if ($totalExpected > 0 && $resolved >= $totalExpected) {
                        $allDone = true;
                        break;
                    }
                }
            }

            try {
                $this->redis->expire($responseKey, self::RESPONSE_TTL);
            } catch (\RedisException) {}

            $this->logger->info('RelayGatewayClient: publish complete', [
                'correlation_id'    => $correlationId,
                'received_response' => $allDone,
                'timed_out'         => !$allDone,
                'resolved'          => $resolved,
                'total_expected'    => $totalExpected,
                'ok'                => $ok,
                'errors'            => !empty($errors) ? $errors : null,
            ]);

            return ['ok' => $ok, 'errors' => $errors];

        } catch (\RedisException $e) {
            $this->logger->error('RelayGatewayClient: Redis error during publish', [
                'error' => $e->getMessage(),
            ]);
            return ['ok' => [], 'errors' => ['gateway' => $e->getMessage()]];
        }
    }

    /**
     * Tell the gateway to open and authenticate connections for a user.
     * Fire-and-forget — no response expected.
     *
     * @param string   $pubkey         User's hex pubkey
     * @param string[] $authRelayUrls  Relay URLs known to require AUTH
     */
    public function warmUserConnections(string $pubkey, array $authRelayUrls): void
    {
        try {
            $this->redis->xAdd(self::CONTROL_STREAM, '*', [
                'action' => 'warm',
                'pubkey' => $pubkey,
                'relays' => json_encode($authRelayUrls),
            ]);

            $this->logger->info('RelayGatewayClient: dispatched warm command', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'relay_count' => count($authRelayUrls),
            ]);
        } catch (\RedisException $e) {
            $this->logger->warning('RelayGatewayClient: failed to dispatch warm command', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the gateway to close all connections for a user.
     * Fire-and-forget — no response expected.
     */
    public function closeUserConnections(string $pubkey): void
    {
        try {
            $this->redis->xAdd(self::CONTROL_STREAM, '*', [
                'action' => 'close',
                'pubkey' => $pubkey,
            ]);

            $this->logger->info('RelayGatewayClient: dispatched close command', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
            ]);
        } catch (\RedisException $e) {
            $this->logger->warning('RelayGatewayClient: failed to dispatch close command', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if the gateway process is alive by pinging the request stream.
     */
    public function isGatewayAlive(): bool
    {
        try {
            $info = $this->redis->xInfo('STREAM', self::REQUEST_STREAM);
            return is_array($info);
        } catch (\RedisException) {
            return false;
        }
    }
}

