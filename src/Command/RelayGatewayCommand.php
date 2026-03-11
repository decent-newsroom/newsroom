<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Nostr\GatewayConnection;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayRegistry;
use App\Util\NostrPhp\RelaySubscriptionHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Subscription\Subscription;
use WebSocket\Message\Ping;
use WebSocket\Message\Text;

/**
 * Long-lived relay gateway process.
 *
 * Maintains WebSocket connections to external Nostr relays on demand,
 * handles NIP-42 AUTH via Mercure roundtrip signing, and serves as the
 * single point of relay communication for all FrankenPHP request workers.
 *
 * Communication protocol:
 *   relay:requests       — query/publish requests from request workers (Redis Streams)
 *   relay:control        — lifecycle commands: warm, close (Redis Streams)
 *   relay:responses:{id} — per-correlation-ID response streams
 *
 * Connection model (on-demand):
 *   All connections are opened lazily when a query or publish first targets a
 *   relay, then kept alive for an idle TTL (default 5 min for shared, 30 min
 *   for user). No persistent connections are held at startup. User warm
 *   commands pre-open connections for AUTH-gated relays before the first
 *   request arrives.
 *
 *   On-demand: keyed by relay URL — opened when needed, closed after idle
 *   User:      keyed by relay::pubkey — opened via warm, authed as user's npub
 */
#[AsCommand(
    name: 'app:relay-gateway',
    description: 'Persistent relay connection gateway with NIP-42 AUTH support',
)]
class RelayGatewayCommand extends Command
{
    private const REQUEST_STREAM = 'relay:requests';
    private const CONTROL_STREAM = 'relay:control';
    private const RESPONSE_PREFIX = 'relay:responses:';
    private const AUTH_PENDING_PREFIX = 'relay_auth_pending:';
    private const AUTH_SIGNED_PREFIX = 'relay_auth_signed:';

    // Resource limits (configurable via options)
    private int $maxConnectionsPerUser = 5;
    private int $maxTotalUserConnections = 200;
    private int $maxSharedConnections = 20;
    private int $userIdleTimeout = 1800; // 30 minutes
    private int $onDemandIdleTimeout = 300; // 5 minutes for on-demand shared connections
    private int $authTimeout = 60; // seconds

    /** @var array<string, GatewayConnection> Keyed by connection key */
    private array $connections = [];

    /**
     * Pending query subscriptions waiting for EOSE from a relay.
     * Keyed by subscriptionId (unique per REQ sent).
     *
     * @var array<string, array{
     *   correlationId: string,
     *   relayUrl: string,
     *   connKey: string,
     *   events: array,
     *   deadline: int,
     *   done: bool,
     * }>
     */
    private array $pendingQueries = [];

    /**
     * Aggregated state per correlationId — tracks how many relay subscriptions
     * are still open so we know when to write the final response.
     *
     * @var array<string, array{
     *   total: int,
     *   done: int,
     *   events: array,
     *   errors: array<string, string>,
     *   deadline: int,
     * }>
     */
    private array $pendingCorrelations = [];

    /**
     * Pending publish requests waiting for OK from a relay.
     * Keyed by a synthetic "{correlationId}::{relayUrl}" key.
     *
     * @var array<string, array{
     *   correlationId: string,
     *   relayUrl: string,
     *   connKey: string,
     *   eventId: string,
     *   deadline: int,
     *   done: bool,
     * }>
     */
    private array $pendingPublishes = [];

    /**
     * Publish payloads deferred because the connection was mid-AUTH when the
     * publish arrived. Keyed by "{correlationId}::{relayUrl}".
     *
     * @var array<string, array{
     *   connKey: string,
     *   payload: string,
     *   eventId: string,
     * }>
     */
    private array $pendingAuthPublishes = [];

    /**
     * Pending AUTH challenges waiting for signed events.
     * @var array<string, array{connKey: string, requestId: string, startedAt: int}>
     */
    private array $pendingAuths = [];

    /**
     * REQs that were deferred because their connection was mid-AUTH when the
     * query arrived. Keyed by subscriptionId.
     *
     * @var array<string, array{
     *   connKey: string,
     *   payload: string,
     * }>
     */
    private array $pendingAuthReqs = [];

    /**
     * Connections that need to be opened asynchronously, one per event-loop tick,
     * so that blocking TCP+TLS handshakes never stall the entire gateway loop.
     *
     * Populated by handleWarm(). Drained by drainPendingConnections().
     *
     * @var list<array{relayUrl: string, pubkey: string}>
     */
    private array $pendingConnections = [];

    private bool $shouldStop = false;
    private RelaySubscriptionHandler $handler;

    /** Track last stream IDs for consumer groups */
    private string $lastRequestId = '$';
    private string $lastControlId = '$';

    public function __construct(
        private readonly \Redis $redis,
        private readonly RelayRegistry $relayRegistry,
        private readonly RelayHealthStore $healthStore,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    // =========================================================================
    // Relay URL normalisation helpers
    // =========================================================================

    /**
     * Resolve a relay URL to the internal (local) address before connecting.
     *
     * The PROJECT relay is the public wss:// hostname of the same strfry
     * instance as LOCAL. The gateway runs inside Docker and must never open
     * a WebSocket to the public hostname — DNS won't resolve it internally
     * and it would add unnecessary TLS + round-trip overhead even if it did.
     *
     * Any URL that matches the project public hostname is silently rewritten
     * to the LOCAL (ws://strfry:7777) address. All other URLs pass through.
     */
    private function resolveRelayUrl(string $url): string
    {
        return $this->relayRegistry->resolveToLocalUrl($url);
    }

    /**
     * Resolve a relay URL to the *public* hostname for use in AUTH challenge
     * messages published to the browser via Mercure.
     *
     * The browser's relay_auth_controller.js puts the relay URL into the
     * kind-22242 ["relay", <url>] tag. The relay validates that tag against
     * the connection it sent the challenge on. If the gateway connected via
     * the LOCAL internal URL, the relay knows the connection as the internal
     * address — but we want the browser to sign with the PUBLIC URL the relay
     * advertises externally, since that is what the relay records as the
     * connection's relay hint.
     *
     * In practice most relays don't care which URL string is in the tag as
     * long as the challenge matches, but using the public URL is more correct
     * and avoids exposing internal Docker hostnames to the browser.
     */
    private function resolveRelayUrlForAuth(string $internalUrl): string
    {
        $localRelay = $this->relayRegistry->getLocalRelay();
        if ($localRelay === null) {
            return $internalUrl;
        }
        $normalize = static fn(string $u): string => rtrim(strtolower($u), '/');
        if ($normalize($internalUrl) === $normalize($localRelay)) {
            return $this->relayRegistry->getPublicUrl() ?? $internalUrl;
        }
        return $internalUrl;
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-user-conns', null, InputOption::VALUE_OPTIONAL, 'Max connections per user', '5')
            ->addOption('max-total-user-conns', null, InputOption::VALUE_OPTIONAL, 'Max total user connections', '200')
            ->addOption('max-shared-conns', null, InputOption::VALUE_OPTIONAL, 'Max on-demand shared connections', '20')
            ->addOption('user-idle-timeout', null, InputOption::VALUE_OPTIONAL, 'User connection idle timeout (seconds)', '1800')
            ->addOption('on-demand-idle-timeout', null, InputOption::VALUE_OPTIONAL, 'On-demand shared connection idle timeout (seconds)', '300')
            ->addOption('auth-timeout', null, InputOption::VALUE_OPTIONAL, 'AUTH roundtrip timeout (seconds)', '60')
            ->addOption('time-limit', null, InputOption::VALUE_OPTIONAL, 'Max runtime in seconds before graceful restart (0=unlimited)', '3600');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Relay Gateway');

        // Apply resource limits from options
        $this->maxConnectionsPerUser = (int) $input->getOption('max-user-conns');
        $this->maxTotalUserConnections = (int) $input->getOption('max-total-user-conns');
        $this->maxSharedConnections = (int) $input->getOption('max-shared-conns');
        $this->userIdleTimeout = (int) $input->getOption('user-idle-timeout');
        $this->onDemandIdleTimeout = (int) $input->getOption('on-demand-idle-timeout');
        $this->authTimeout = (int) $input->getOption('auth-timeout');
        $timeLimit = (int) $input->getOption('time-limit');
        $startedAt = time();

        // Register signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }

        $this->handler = new RelaySubscriptionHandler($this->logger);

        // Initialize streams and clean up any orphaned response streams from
        // a previous gateway session that was interrupted mid-publish.
        $this->initializeStreams();
        $this->cleanupOrphanedResponseStreams();

        // On-demand connection model: no shared connections opened at startup.
        // Connections are opened lazily when a query or publish first targets a
        // relay, kept alive for the on-demand idle TTL, then closed. User warm
        // commands pre-open authenticated connections for AUTH-gated relays.
        $io->success('Gateway started (on-demand connections). Entering event loop.');

        // Main event loop
        $lastMaintenanceCheck = 0;

        while (!$this->shouldStop) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                // 1. Process incoming requests from Redis Streams (non-blocking)
                $this->processRequestStream();
                $this->processControlStream();

                // 2. Open one pending connection per tick — keeps TCP handshakes
                //    from blocking the rest of the loop for multiple seconds.
                $this->drainPendingConnections();

                // 3. Read from all open WebSocket connections (non-blocking)
                $this->processWebSocketMessages();

                // 4. Check for completed AUTH roundtrips
                $this->checkPendingAuths();

                // 5. Periodic maintenance (every 60 seconds)
                if (time() - $lastMaintenanceCheck >= 60) {
                    $this->performMaintenance();
                    $lastMaintenanceCheck = time();
                }

                // 6. Time-limit check — graceful restart to pick up code changes
                if ($timeLimit > 0 && (time() - $startedAt) >= $timeLimit) {
                    $io->info(sprintf('Time limit of %d seconds reached. Shutting down for restart.', $timeLimit));
                    $this->shouldStop = true;
                }

            } catch (\Throwable $e) {
                $this->logger->error('Gateway event loop error', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
                // Don't crash — sleep briefly and continue
                usleep(100_000); // 100ms
            }

            // Short sleep — sockets are non-blocking (0ms timeout) so this
            // just yields the CPU briefly between sweeps.
            usleep(5_000); // 5ms
        }

        // Graceful shutdown
        $io->section('Shutting down gateway...');
        $this->closeAllConnections();
        $io->success('Gateway stopped.');

        return Command::SUCCESS;
    }

    // =========================================================================
    // Stream initialization
    // =========================================================================

    private function initializeStreams(): void
    {
        foreach ([
            self::REQUEST_STREAM => 'lastRequestId',
            self::CONTROL_STREAM => 'lastControlId',
        ] as $stream => $property) {
            $this->$property = $this->getStreamLastId($stream);
        }

        $this->logger->debug('Gateway: streams initialized', [
            'request_id' => $this->lastRequestId,
            'control_id' => $this->lastControlId,
        ]);
    }

    /**
     * Get the last entry ID currently in a Redis stream, so the gateway skips
     * messages written before startup (avoids replaying stale requests on restart).
     *
     * Falls back to '0-0' when the stream doesn't exist yet so we don't miss
     * the very first message written after startup.
     */
    private function getStreamLastId(string $stream): string
    {
        try {
            // xRevRange with count=1 returns the single most-recent entry.
            // This works regardless of phpredis version or xInfo key-name quirks.
            $entries = $this->redis->xRevRange($stream, '+', '-', 1);
            if (!empty($entries)) {
                $ids = array_keys($entries);
                return $ids[0]; // most recent ID
            }
        } catch (\Throwable) {}

        // Stream doesn't exist — start from the beginning so the first real
        // message after startup is never missed.
        return '0-0';
    }

    /**
     * On startup, find any response streams left by a previous session that was
     * interrupted mid-publish (container restart, crash). These have a 'total'
     * header entry but no relay partials and no TTL. Write failure partials so
     * any PHP worker still polling xRead on that stream can unblock immediately.
     */
    private function cleanupOrphanedResponseStreams(): void
    {
        try {
            $keys = $this->redis->keys(self::RESPONSE_PREFIX . '*');
            if (empty($keys)) {
                return;
            }
            foreach ($keys as $key) {
                $ttl = $this->redis->ttl($key);
                if ($ttl > 0) {
                    continue; // Already has a TTL — being cleaned up normally
                }
                $entries = $this->redis->xRange($key, '-', '+', 100);
                if (empty($entries)) {
                    $this->redis->del($key);
                    continue;
                }
                $total = null;
                $hasRelayPartial = false;
                foreach ($entries as $entry) {
                    if (isset($entry['total'])) {
                        $total = (int) $entry['total'];
                    }
                    if (isset($entry['relay'])) {
                        $hasRelayPartial = true;
                        break;
                    }
                }
                if ($total !== null && !$hasRelayPartial) {
                    $correlationId = substr($key, strlen(self::RESPONSE_PREFIX));
                    $this->logger->info('Gateway: cleaning up orphaned publish stream from previous session', [
                        'correlation_id' => $correlationId,
                        'total_expected' => $total,
                    ]);
                    for ($i = 0; $i < $total; $i++) {
                        $this->redis->xAdd($key, '*', [
                            'relay'   => 'unknown',
                            'ok'      => 'false',
                            'message' => 'Gateway restarted — publish result unknown',
                            'done'    => ($i === $total - 1) ? 'true' : 'false',
                        ]);
                    }
                    $this->redis->expire($key, 30);
                } else {
                    // Has partial data but no TTL — give it a short window to expire
                    $this->redis->expire($key, 60);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Gateway: failed to clean orphaned response streams', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Connection management
    // =========================================================================

    private function openConnection(string $relayUrl, ?string $pubkey): GatewayConnection
    {
        $conn = new GatewayConnection($relayUrl, $pubkey);
        $key = $conn->getKey();

        // Check if already open
        if (isset($this->connections[$key]) && $this->connections[$key]->isConnected()) {
            return $this->connections[$key];
        }

        $this->logger->info('Gateway: opening connection', [
            'relay' => $relayUrl,
            'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : 'on-demand',
        ]);

        try {
            // Use swentel Relay (wraps WebSocket\Client) — same as TweakedRequest
            $relay = new Relay($relayUrl);
            $relay->connect();

            $client = $relay->getClient();
            // 0 = non-blocking drain: receive() returns immediately if the OS
            // buffer is empty, throwing a timeout exception (caught in
            // processWebSocketMessages as "nothing to read"). With N connections
            // a 1-second timeout makes each sweep take up to N seconds, which
            // blows past the correlation deadline long before EOSE arrives.
            $client->setTimeout(0);

            $conn->relay = $relay;
            $conn->connected = true;
            $conn->connectedAt = time();
            $conn->lastActivity = time();
            $conn->reconnectAttempts = 0;

            $this->connections[$key] = $conn;

            // Record success in health store
            $this->healthStore->recordSuccess($relayUrl);

            return $conn;

        } catch (\Throwable $e) {
            $this->logger->warning('Gateway: failed to open connection', [
                'relay' => $relayUrl,
                'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : 'on-demand',
                'error' => $e->getMessage(),
            ]);
            $this->healthStore->recordFailure($relayUrl);
            throw $e;
        }
    }

    private function closeConnection(string $key): void
    {
        if (!isset($this->connections[$key])) {
            return;
        }

        $conn = $this->connections[$key];
        $conn->markDisconnected();
        try {
            $conn->relay?->disconnect();
        } catch (\Throwable) {
            // Ignore close errors
        }

        $this->logger->debug('Gateway: closed connection', [
            'key' => $key,
            'relay' => $conn->relayUrl,
            'pubkey' => $conn->pubkey ? substr($conn->pubkey, 0, 8) . '...' : 'on-demand',
        ]);

        unset($this->connections[$key]);
    }

    private function closeAllConnections(): void
    {
        foreach (array_keys($this->connections) as $key) {
            $this->closeConnection($key);
        }
    }

    // =========================================================================
    // Redis Stream processing
    // =========================================================================

    private function processRequestStream(): void
    {
        try {
            // Safety: if lastRequestId is the literal '$' sentinel (old code or
            // failed initialization), resolve to actual last ID now. '$' causes
            // xRead to miss messages written between block windows.
            if ($this->lastRequestId === '$') {
                $this->lastRequestId = $this->getStreamLastId(self::REQUEST_STREAM);
                $this->logger->warning('Gateway: recovered lastRequestId from "$"', [
                    'resolved' => $this->lastRequestId,
                ]);
            }

            $messages = $this->redis->xRead(
                [self::REQUEST_STREAM => $this->lastRequestId],
                5, // batch size
                10, // block 10ms — tight loop so WebSocket responses aren't delayed
            );

            if (!$messages || !isset($messages[self::REQUEST_STREAM])) {
                return;
            }

            $this->logger->info('Gateway: processing request stream batch', [
                'count'          => count($messages[self::REQUEST_STREAM]),
                'lastRequestId'  => $this->lastRequestId,
            ]);

            foreach ($messages[self::REQUEST_STREAM] as $messageId => $data) {
                $this->lastRequestId = $messageId;
                $this->handleRequest($data);
            }

            // Trim old messages — use rawCommand to avoid phpredis stub signature issues
            $this->redis->rawCommand('XTRIM', self::REQUEST_STREAM, 'MAXLEN', '~', '1000');

        } catch (\Throwable $e) {
            $this->logger->error('Gateway: error processing request stream', [
                'error'          => $e->getMessage(),
                'class'          => get_class($e),
                'lastRequestId'  => $this->lastRequestId,
            ]);
        }
    }

    private function processControlStream(): void
    {
        try {
            if ($this->lastControlId === '$') {
                $this->lastControlId = $this->getStreamLastId(self::CONTROL_STREAM);
                $this->logger->warning('Gateway: recovered lastControlId from "$"', [
                    'resolved' => $this->lastControlId,
                ]);
            }

            $messages = $this->redis->xRead(
                [self::CONTROL_STREAM => $this->lastControlId],
                5,
                5, // block 5ms
            );

            if (!$messages || !isset($messages[self::CONTROL_STREAM])) {
                return;
            }

            foreach ($messages[self::CONTROL_STREAM] as $messageId => $data) {
                $this->lastControlId = $messageId;
                $this->handleControl($data);
            }

            $this->redis->rawCommand('XTRIM', self::CONTROL_STREAM, 'MAXLEN', '~', '1000');

        } catch (\Throwable $e) {
            $this->logger->error('Gateway: error processing control stream', [
                'error'         => $e->getMessage(),
                'class'         => get_class($e),
                'lastControlId' => $this->lastControlId,
            ]);
        }
    }

    // =========================================================================
    // Request handling
    // =========================================================================

    private function handleRequest(array $data): void
    {
        $action = $data['action'] ?? '';
        $correlationId = $data['id'] ?? '';

        if (!$correlationId) {
            $this->logger->warning('Gateway: request missing correlation ID');
            return;
        }

        match ($action) {
            'query' => $this->handleQueryRequest($data, $correlationId),
            'publish' => $this->handlePublishRequest($data, $correlationId),
            default => $this->logger->warning('Gateway: unknown request action', ['action' => $action]),
        };
    }

    private function handleQueryRequest(array $data, string $correlationId): void
    {
        $relayUrls = json_decode($data['relays'] ?? '[]', true) ?: [];
        $filter = json_decode($data['filter'] ?? '{}', true) ?: [];
        $pubkey = $data['pubkey'] ?? '';
        $pubkey = $pubkey !== '' ? $pubkey : null;
        $timeout = (int) ($data['timeout'] ?? 15);

        // Rewrite any project public URL to the internal local URL.
        // The gateway runs inside Docker — it must connect via the internal hostname.
        $relayUrls = array_values(array_unique(array_map(
            fn(string $u) => $this->resolveRelayUrl($u),
            $relayUrls,
        )));

        $this->logger->debug('Gateway: handling query (non-blocking)', [
            'correlation_id' => $correlationId,
            'relays' => $relayUrls,
            'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
        ]);

        $deadline = time() + $timeout;
        $errors = [];
        $registeredCount = 0;

        foreach ($relayUrls as $relayUrl) {
            try {
                $conn = $this->routeConnection($relayUrl, $pubkey);

                // Build REQ payload (needed for both send and defer paths)
                $subscription = new Subscription();
                $subscriptionId = $subscription->setId();

                $filterObj = new Filter();
                if (isset($filter['kinds'])) {
                    $filterObj->setKinds($filter['kinds']);
                }
                if (isset($filter['authors'])) {
                    $filterObj->setAuthors($filter['authors']);
                }
                if (isset($filter['limit'])) {
                    $filterObj->setLimit($filter['limit']);
                }
                if (isset($filter['since'])) {
                    $filterObj->setSince($filter['since']);
                }
                if (isset($filter['until'])) {
                    $filterObj->setUntil($filter['until']);
                }
                if (isset($filter['ids'])) {
                    $filterObj->setIds($filter['ids']);
                }
                // NIP-01 tag filters: #e, #p, #t, #d, #a, etc.
                foreach ($filter as $key => $value) {
                    if (str_starts_with($key, '#') && strlen($key) === 2 && is_array($value)) {
                        $filterObj->setTag($key, $value);
                    }
                }

                $reqPayload = (new RequestMessage($subscriptionId, [$filterObj]))->generate();

                // Determine the connection key for tracking. If no connection
                // yet (on-demand), use the anticipated key that will be created
                // when drainPendingConnections opens the connection.
                $connKey = $conn ? $conn->getKey() : GatewayConnection::buildKey($relayUrl);

                // Register subscription in pending-query table regardless of
                // connection state so sweepTimedOutPending can account for it.
                $this->pendingQueries[$subscriptionId] = [
                    'correlationId' => $correlationId,
                    'relayUrl'      => $relayUrl,
                    'connKey'       => $connKey,
                    'events'        => [],
                    'deadline'      => $deadline,
                    'done'          => false,
                ];
                $registeredCount++;

                if (!$conn || !$conn->isConnected()) {
                    // Connection is being opened on-demand — defer the REQ.
                    // It will be flushed by checkPendingAuths → flushDeferredForConnection
                    // once the connection opens and settles.
                    $this->logger->debug('Gateway: deferring REQ until on-demand connection opens', [
                        'relay'           => $relayUrl,
                        'subscription_id' => $subscriptionId,
                    ]);
                    $this->pendingAuthReqs[$subscriptionId] = [
                        'connKey' => $connKey,
                        'payload' => $reqPayload,
                    ];
                    continue;
                }

                // If the connection is mid-AUTH, defer the REQ.
                if ($conn->authStatus === 'pending') {
                    $this->logger->info('Gateway: deferring REQ until AUTH completes', [
                        'relay'           => $relayUrl,
                        'subscription_id' => $subscriptionId,
                        'correlation_id'  => $correlationId,
                    ]);
                    $this->pendingAuthReqs[$subscriptionId] = [
                        'connKey' => $conn->getKey(),
                        'payload' => $reqPayload,
                    ];
                    continue;
                }

                $conn->getClient()->text($reqPayload);
                $conn->touch();

            } catch (\Throwable $e) {
                $errors[$relayUrl] = $e->getMessage();
                $this->healthStore->recordFailure($relayUrl);

                // Mark the connection as disconnected so maintenance reconnects it
                if (isset($conn) && $conn !== null) {
                    $conn->markDisconnected();
                }

                $this->logger->warning('Gateway: query REQ failed for relay', [
                    'relay' => $relayUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Track per-correlationId aggregation state
        if ($registeredCount > 0) {
            $this->pendingCorrelations[$correlationId] = [
                'total'    => $registeredCount,
                'done'     => 0,
                'events'   => [],
                'errors'   => $errors,
                'deadline' => $deadline,
            ];
        } else {
            // All relays failed immediately — write response right away
            $this->writeResponse($correlationId, [], $errors);
        }
    }

    private function handlePublishRequest(array $data, string $correlationId): void
    {
        $relayUrls = json_decode($data['relays'] ?? '[]', true) ?: [];
        $event = json_decode($data['event'] ?? '{}', true) ?: [];
        $pubkey = $data['pubkey'] ?? '';
        $pubkey = $pubkey !== '' ? $pubkey : null;
        $timeout = (int) ($data['timeout'] ?? 10);
        $eventId = $event['id'] ?? '';

        // Rewrite any project public URL to the internal local URL.
        $relayUrls = array_values(array_unique(array_map(
            fn(string $u) => $this->resolveRelayUrl($u),
            $relayUrls,
        )));

        $this->logger->info('Gateway: handling publish request', [
            'correlation_id' => $correlationId,
            'relay_count'    => count($relayUrls),
            'relays'         => $relayUrls,
            'event_id'       => substr($eventId, 0, 16),
            'pubkey'         => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
            'total_connections' => count($this->connections),
            'connected_count'  => count(array_filter($this->connections, fn($c) => $c->isConnected())),
        ]);


        $errors = [];
        $deadline = time() + $timeout;
        $registeredCount = 0;    // total relays we'll track (pending + immediate)
        $immediatelyDone = 0;    // relays already resolved (no-conn, send-exception)
        $totalRelays = count($relayUrls);

        // Write a header entry first so the client knows how many relay results
        // to expect. This allows it to stop reading as soon as it has collected
        // results for all relays, rather than polling until the deadline.
        if ($totalRelays > 0) {
            try {
                $responseKey = self::RESPONSE_PREFIX . $correlationId;
                $this->redis->xAdd($responseKey, '*', [
                    'total' => (string) $totalRelays,
                ]);
            } catch (\RedisException) {}
        }

        foreach ($relayUrls as $relayUrl) {
            $pendingKey = $correlationId . '::' . $relayUrl;

            try {
                $conn = $this->routeConnectionForPublish($relayUrl, $pubkey);

                // Determine the connection key for tracking. If no connection
                // yet (on-demand), use the anticipated key.
                $connKey = $conn ? $conn->getKey() : GatewayConnection::buildKey($relayUrl);

                // Always register the pending-publish slot before sending so we
                // can track the OK response regardless of the connection path.
                $this->pendingPublishes[$pendingKey] = [
                    'correlationId' => $correlationId,
                    'relayUrl'      => $relayUrl,
                    'connKey'       => $connKey,
                    'eventId'       => $eventId,
                    'deadline'      => $deadline,
                    'done'          => false,
                ];
                $registeredCount++;

                if (!$conn || !$conn->isConnected()) {
                    // Connection is being opened on-demand — defer the EVENT.
                    // It will be flushed by checkPendingAuths → flushDeferredForConnection.
                    $this->logger->debug('Gateway: deferring EVENT publish until on-demand connection opens', [
                        'relay'          => $relayUrl,
                        'correlation_id' => $correlationId,
                        'event_id'       => substr($eventId, 0, 16),
                    ]);
                    $this->pendingAuthPublishes[$pendingKey] = [
                        'connKey' => $connKey,
                        'payload' => json_encode(['EVENT', $event]),
                        'eventId' => $eventId,
                    ];
                    continue;
                }

                // Decide whether to send immediately or defer.
                //   'authed'              → send now
                //   'none' + connAge >= 1 → no AUTH challenge arrived, relay is open, send now
                //   anything else         → defer until AUTH resolves or settle window passes
                $connAge = time() - $conn->connectedAt;
                $readyToSend = $conn->authStatus === 'authed'
                    || ($conn->authStatus === 'none' && $connAge >= 1);

                if (!$readyToSend) {
                    $this->logger->info('Gateway: deferring EVENT publish until AUTH resolves', [
                        'relay'          => $relayUrl,
                        'auth_status'    => $conn->authStatus,
                        'conn_age'       => $connAge,
                        'correlation_id' => $correlationId,
                        'event_id'       => substr($eventId, 0, 16),
                    ]);
                    $this->pendingAuthPublishes[$pendingKey] = [
                        'connKey' => $conn->getKey(),
                        'payload' => json_encode(['EVENT', $event]),
                        'eventId' => $eventId,
                    ];
                    continue;
                }

                // Promote 'none' connections that passed the settle window
                if ($conn->authStatus === 'none') {
                    $conn->authStatus = 'authed';
                    $this->logger->info('Gateway: promoting settled connection to authed at publish time', [
                        'relay'    => $relayUrl,
                        'conn_age' => $connAge,
                    ]);
                }

                $conn->getClient()->text(json_encode(['EVENT', $event]));
                $conn->touch();

            } catch (\Throwable $e) {
                $errors[$relayUrl] = $e->getMessage();
                $this->healthStore->recordFailure($relayUrl);

                // Mark the connection as disconnected so maintenance reconnects it
                if (isset($conn) && $conn !== null) {
                    $conn->markDisconnected();
                }

                $this->logger->warning('Gateway: publish EVENT failed for relay', [
                    'relay' => $relayUrl,
                    'error' => $e->getMessage(),
                ]);
                // Write immediate partial so the client unblocks for this relay
                $registeredCount++;
                $immediatelyDone++;
                $this->writePartialPublishResponse(
                    $correlationId, $relayUrl, false, $e->getMessage(),
                    $registeredCount >= $totalRelays,
                );
            }
        }

        if ($registeredCount === 0) {
            $this->writePublishResponse($correlationId, [], $errors);
        } else {
            if (!isset($this->pendingCorrelations[$correlationId])) {
                $this->pendingCorrelations[$correlationId] = [
                    'total'    => $registeredCount,
                    // Start done at the number of already-resolved relays so the
                    // correlation completes correctly when the remaining pending
                    // relays resolve (completePendingPublish increments done).
                    'done'     => $immediatelyDone,
                    'events'   => [],
                    'errors'   => $errors,
                    'deadline' => $deadline,
                    'ok'       => [],   // presence of this key marks it as a publish correlation
                ];
            }
        }
    }

    // =========================================================================
    // Control handling
    // =========================================================================

    private function handleControl(array $data): void
    {
        $action = $data['action'] ?? '';

        match ($action) {
            'warm' => $this->handleWarm($data),
            'close' => $this->handleClose($data),
            default => $this->logger->warning('Gateway: unknown control action', ['action' => $action]),
        };
    }

    private function handleWarm(array $data): void
    {
        $pubkey = $data['pubkey'] ?? '';
        $relayUrls = json_decode($data['relays'] ?? '[]', true) ?: [];

        if (!$pubkey || empty($relayUrls)) {
            return;
        }

        // Rewrite any project public URL to the internal local URL.
        $relayUrls = array_values(array_unique(array_map(
            fn(string $u) => $this->resolveRelayUrl($u),
            $relayUrls,
        )));

        $this->logger->info('Gateway: queueing user connections for warm-up', [
            'pubkey'      => substr($pubkey, 0, 8) . '...',
            'relay_count' => count($relayUrls),
        ]);

        // Enforce per-user limit up-front so we don't queue more than we'll open
        $currentUserConns = $this->countUserConnections($pubkey);
        $remaining = $this->maxConnectionsPerUser - $currentUserConns;
        $relayUrls = array_slice($relayUrls, 0, max(0, $remaining));

        // Enforce total user connection limit — evict now so we have room
        $totalUserConns = $this->countAllUserConnections();
        if ($totalUserConns >= $this->maxTotalUserConnections) {
            $this->evictIdlestUserConnection();
        }

        // Enqueue — drainPendingConnections() will open one per event-loop tick
        // so blocking TCP+TLS handshakes never stall the gateway loop.
        foreach ($relayUrls as $relayUrl) {
            $key = GatewayConnection::buildKey($relayUrl, $pubkey);
            // Skip if already open or already queued
            if (isset($this->connections[$key]) && $this->connections[$key]->isConnected()) {
                continue;
            }
            $alreadyQueued = false;
            foreach ($this->pendingConnections as $pending) {
                if ($pending['relayUrl'] === $relayUrl && $pending['pubkey'] === $pubkey) {
                    $alreadyQueued = true;
                    break;
                }
            }
            if (!$alreadyQueued) {
                $this->pendingConnections[] = ['relayUrl' => $relayUrl, 'pubkey' => $pubkey];
            }
        }
    }

    /**
     * Enqueue an on-demand (shared, no pubkey) connection for opening on the
     * next event loop tick. Respects the max shared connections limit.
     */
    private function enqueueOnDemandConnection(string $relayUrl): void
    {
        $key = GatewayConnection::buildKey($relayUrl);

        // Already open or already queued — skip
        if (isset($this->connections[$key]) && $this->connections[$key]->isConnected()) {
            return;
        }
        foreach ($this->pendingConnections as $pending) {
            if ($pending['relayUrl'] === $relayUrl && $pending['pubkey'] === null) {
                return; // already queued
            }
        }

        // Enforce pool ceiling
        $sharedCount = 0;
        foreach ($this->connections as $conn) {
            if ($conn->isShared()) {
                $sharedCount++;
            }
        }
        if ($sharedCount >= $this->maxSharedConnections) {
            // Evict the idlest shared connection to make room
            $this->evictIdlestSharedConnection();
        }

        $this->pendingConnections[] = ['relayUrl' => $relayUrl, 'pubkey' => null];
    }

    /**
     * Open one pending connection per event-loop tick.
     *
     * Each TCP+TLS handshake (relay->connect()) is blocking and can take
     * several seconds. Running them one-at-a-time here, rather than all at
     * once inside handleWarm or handlePublishRequest, ensures the gateway
     * loop continues processing WebSocket messages and Redis streams while
     * connections are being established.
     */
    private function drainPendingConnections(): void
    {
        if (empty($this->pendingConnections)) {
            return;
        }

        $next = array_shift($this->pendingConnections);
        $relayUrl = $next['relayUrl'];
        $pubkey   = $next['pubkey'];

        // Check again — may have been opened by a concurrent warm or publish
        $key = GatewayConnection::buildKey($relayUrl, $pubkey);
        if (isset($this->connections[$key]) && $this->connections[$key]->isConnected()) {
            return;
        }

        try {
            $this->openConnection($relayUrl, $pubkey);
            $this->logger->info('Gateway: opened queued connection', [
                'relay'  => $relayUrl,
                'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : 'on-demand',
                'remaining_queue' => count($this->pendingConnections),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Gateway: failed to open queued connection', [
                'relay'  => $relayUrl,
                'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : 'on-demand',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function handleClose(array $data): void
    {
        $pubkey = $data['pubkey'] ?? '';
        if (!$pubkey) {
            return;
        }

        $this->logger->info('Gateway: closing all user connections', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
        ]);

        // Remove any queued connections for this user before they're opened
        $this->pendingConnections = array_values(array_filter(
            $this->pendingConnections,
            fn(array $p) => $p['pubkey'] !== $pubkey,
        ));

        foreach ($this->connections as $key => $conn) {
            if ($conn->pubkey === $pubkey) {
                $this->closeConnection($key);
            }
        }
    }

    // =========================================================================
    // WebSocket message processing
    // =========================================================================

    private function processWebSocketMessages(): void
    {
        foreach ($this->connections as $key => $conn) {
            if (!$conn->isConnected()) {
                continue;
            }

            try {
                $resp = $conn->getClient()->receive();

                if ($resp instanceof Ping) {
                    $this->handler->handlePing($conn->getClient());
                    $conn->touch();
                    continue;
                }

                if (!($resp instanceof Text)) {
                    continue;
                }

                $conn->touch();
                $decoded = json_decode($resp->getContent(), true);
                if (!is_array($decoded)) {
                    continue;
                }

                $type = $decoded[0] ?? '';

                switch ($type) {
                    case 'AUTH':
                        $challenge = $decoded[1] ?? null;
                        if ($challenge) {
                            $this->handleAuthChallenge($conn, $challenge);
                        }
                        break;

                    case 'EVENT':
                        // subscriptionId is $decoded[1], event is $decoded[2]
                        $subId = $decoded[1] ?? null;
                        if ($subId && isset($this->pendingQueries[$subId]) && !$this->pendingQueries[$subId]['done']) {
                            if (isset($decoded[2]) && is_array($decoded[2])) {
                                $this->pendingQueries[$subId]['events'][] = $decoded[2];
                                $this->healthStore->recordEventReceived($conn->relayUrl);
                            }
                        }
                        break;

                    case 'EOSE':
                        $subId = $decoded[1] ?? null;
                        if ($subId && isset($this->pendingQueries[$subId]) && !$this->pendingQueries[$subId]['done']) {
                            $this->completeQuery($subId, $conn);
                        }
                        break;

                    case 'OK':
                        // ["OK", event_id, true/false, message]
                        $okEventId = $decoded[1] ?? null;
                        $accepted = ($decoded[2] ?? false) === true;
                        $this->completePendingPublish($conn, $okEventId, $accepted);
                        break;

                    case 'CLOSED':
                        // NIP-01: ["CLOSED", <subscription_id>, <message>]
                        // Relay refused or terminated the subscription. This is an
                        // error condition (e.g., "auth-required:", "error:"). Complete
                        // the query as failed and record the reason.
                        $subId = $decoded[1] ?? null;
                        $reason = $decoded[2] ?? 'Relay closed subscription';
                        if ($subId && isset($this->pendingQueries[$subId]) && !$this->pendingQueries[$subId]['done']) {
                            $relayUrl = $this->pendingQueries[$subId]['relayUrl'];
                            $correlationId = $this->pendingQueries[$subId]['correlationId'];
                            $this->logger->warning('Gateway: relay CLOSED subscription', [
                                'relay'           => $relayUrl,
                                'subscription_id' => $subId,
                                'reason'          => $reason,
                            ]);
                            // Record as error in the correlation
                            if (isset($this->pendingCorrelations[$correlationId])) {
                                $this->pendingCorrelations[$correlationId]['errors'][$relayUrl] = $reason;
                            }
                            $this->completeQueryWithError($subId, $conn);
                        }
                        break;

                    case 'NOTICE':
                        // NIP-01: ["NOTICE", <message>]
                        // Human-readable relay notice. No subscription ID — just log it.
                        $message = $decoded[1] ?? '';
                        $this->logger->info('Gateway: relay NOTICE', [
                            'relay'   => $conn->relayUrl,
                            'message' => $message,
                        ]);
                        break;
                }

            } catch (\Throwable $e) {
                // Timeout is normal for non-blocking reads
                if (!$this->isTimeoutError($e)) {
                    $conn->markDisconnected();
                    $this->logger->debug('Gateway: WebSocket read error, marking disconnected', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // After processing all WebSocket messages, flush any correlations
        // where every subscription has now completed.
        $this->flushCompletedCorrelations();

        // Sweep for timed-out pending queries/publishes
        $this->sweepTimedOutPending();
    }

    /**
     * Mark a pending query subscription as done, flush its events to the
     * response stream immediately (partial, eose:false), and update the
     * parent correlation counter.
     *
     * Writing per-relay as each one completes means the client starts receiving
     * events from fast relays without waiting for slow or stalled ones.
     */
    private function completeQuery(string $subscriptionId, GatewayConnection $conn): void
    {
        $pending = &$this->pendingQueries[$subscriptionId];
        $pending['done'] = true;

        // Send CLOSE for this subscription
        try {
            $this->handler->sendClose($conn->getClient(), $subscriptionId);
        } catch (\Throwable) {}

        $this->healthStore->recordSuccess($pending['relayUrl']);

        $correlationId = $pending['correlationId'];
        if (!isset($this->pendingCorrelations[$correlationId])) {
            return;
        }

        // Flush this relay's events to Redis immediately (eose:false — more may come)
        if (!empty($pending['events'])) {
            $this->writePartialResponse($correlationId, $pending['events']);
        }

        // Merge into correlation totals
        $this->pendingCorrelations[$correlationId]['events'] = array_merge(
            $this->pendingCorrelations[$correlationId]['events'],
            $pending['events'],
        );
        $this->pendingCorrelations[$correlationId]['done']++;
    }

    /**
     * Complete a query due to relay CLOSED — same as completeQuery() but records
     * a failure (not success) and does NOT send CLOSE back (the relay already
     * terminated the subscription).
     */
    private function completeQueryWithError(string $subscriptionId, GatewayConnection $conn): void
    {
        $pending = &$this->pendingQueries[$subscriptionId];
        $pending['done'] = true;

        // Don't send CLOSE — the relay initiated the close
        $this->healthStore->recordFailure($pending['relayUrl']);

        $correlationId = $pending['correlationId'];
        if (!isset($this->pendingCorrelations[$correlationId])) {
            return;
        }

        // Still flush any events collected before the CLOSED
        if (!empty($pending['events'])) {
            $this->writePartialResponse($correlationId, $pending['events']);
        }

        $this->pendingCorrelations[$correlationId]['events'] = array_merge(
            $this->pendingCorrelations[$correlationId]['events'],
            $pending['events'],
        );
        $this->pendingCorrelations[$correlationId]['done']++;
    }

    /**
     * Mark the pending publish matching the relay's OK response as done.
     *
     * Matches by event ID (present in the OK message per NIP-01) on the given
     * connection. Falls back to first-match by connection key if the event ID
     * is absent (non-standard relay behaviour).
     */
    private function completePendingPublish(GatewayConnection $conn, ?string $eventId, bool $accepted): void
    {
        foreach ($this->pendingPublishes as $pendingKey => &$pending) {
            if ($pending['done'] || $pending['connKey'] !== $conn->getKey()) {
                continue;
            }

            // Skip if we have an event ID to match on and it doesn't match
            if ($eventId !== null && $pending['eventId'] !== '' && $pending['eventId'] !== $eventId) {
                continue;
            }

            $pending['done'] = true;
            $relayUrl = $pending['relayUrl'];
            $correlationId = $pending['correlationId'];

            if ($accepted) {
                $this->healthStore->recordSuccess($relayUrl);
            }

            if (isset($this->pendingCorrelations[$correlationId])) {
                $this->pendingCorrelations[$correlationId]['ok'][$relayUrl] = $accepted;
                $this->pendingCorrelations[$correlationId]['done']++;

                // Write a partial publish response immediately so the client can
                // record this relay's result without waiting for slow/stalled relays.
                $total = $this->pendingCorrelations[$correlationId]['total'];
                $done  = $this->pendingCorrelations[$correlationId]['done'];
                $this->writePartialPublishResponse(
                    $correlationId,
                    $relayUrl,
                    $accepted,
                    '',
                    $done >= $total,
                );
            }

            $this->logger->debug('Gateway: publish OK received', [
                'relay'    => $relayUrl,
                'event_id' => substr($eventId ?? '', 0, 16),
                'accepted' => $accepted,
            ]);

            return;
        }
        unset($pending);
    }

    /**
     * For each correlationId where all subscriptions/publishes are done,
     * flush the final response and clean up.
     *
     * Publish: per-relay partial responses are written by completePendingPublish
     * as each relay resolves; the last partial already carries done:true. This
     * method only needs to do cleanup for publish correlations.
     *
     * Query: the final writeResponse (eose:true) is written here because queries
     * use a different partial/final split.
     */
    private function flushCompletedCorrelations(): void
    {
        foreach ($this->pendingCorrelations as $correlationId => $state) {
            if ($state['done'] < $state['total']) {
                continue;
            }

            if (isset($state['ok'])) {
                // Publish — partials already written; just clean up tracking state
                $this->cleanupCorrelation($correlationId);
            } else {
                // Query — write the final (eose:true) response
                $this->writeResponse($correlationId, $state['events'], $state['errors']);
                $this->cleanupCorrelation($correlationId);
            }
        }
    }

    /**
     * Sweep for pending queries/publishes that have passed their deadline and
     * force-complete them with whatever events/status we have so far.
     */
    private function sweepTimedOutPending(): void
    {
        $now = time();

        // Timed-out query subscriptions
        foreach ($this->pendingQueries as $subId => $pending) {
            if ($pending['done'] || $pending['deadline'] > $now) {
                continue;
            }

            $this->logger->debug('Gateway: query subscription timed out', [
                'subscription_id' => $subId,
                'correlation_id'  => $pending['correlationId'],
                'relay'           => $pending['relayUrl'],
            ]);

            // Send CLOSE if possible
            $conn = $this->connections[$pending['connKey']] ?? null;
            if ($conn && $conn->isConnected()) {
                try {
                    $this->handler->sendClose($conn->getClient(), $subId);
                } catch (\Throwable) {}
            }

            $this->pendingQueries[$subId]['done'] = true;

            // Merge partial events into correlation
            $correlationId = $pending['correlationId'];
            if (isset($this->pendingCorrelations[$correlationId])) {
                $this->pendingCorrelations[$correlationId]['events'] = array_merge(
                    $this->pendingCorrelations[$correlationId]['events'],
                    $pending['events'],
                );
                $this->pendingCorrelations[$correlationId]['done']++;
            }
        }

        // Timed-out publish slots
        foreach ($this->pendingPublishes as $pendingKey => $pending) {
            if ($pending['done'] || $pending['deadline'] > $now) {
                continue;
            }

            $this->logger->debug('Gateway: publish timed out waiting for OK', [
                'pending_key'    => $pendingKey,
                'correlation_id' => $pending['correlationId'],
                'relay'          => $pending['relayUrl'],
            ]);

            $this->pendingPublishes[$pendingKey]['done'] = true;

            $correlationId = $pending['correlationId'];
            if (isset($this->pendingCorrelations[$correlationId])) {
                // No OK received — treat as false
                $this->pendingCorrelations[$correlationId]['ok'][$pending['relayUrl']] = false;
                $this->pendingCorrelations[$correlationId]['done']++;

                // Write a partial immediately so the client unblocks for this relay
                $total = $this->pendingCorrelations[$correlationId]['total'];
                $done  = $this->pendingCorrelations[$correlationId]['done'];
                $this->writePartialPublishResponse(
                    $correlationId,
                    $pending['relayUrl'],
                    false,
                    'Timeout waiting for relay OK',
                    $done >= $total,
                );
            }
        }

        // Now flush any correlations that became complete due to timeouts
        $this->flushCompletedCorrelations();

        // Also force-flush correlations whose deadline has expired (edge case:
        // a relay never sends EOSE / OK and the deadline has passed).
        foreach ($this->pendingCorrelations as $correlationId => $state) {
            if ($state['deadline'] > $now) {
                continue;
            }

            $this->logger->debug('Gateway: force-flushing expired correlation', [
                'correlation_id' => $correlationId,
                'done' => $state['done'],
                'total' => $state['total'],
            ]);

            if (isset($state['ok'])) {
                // Publish correlation — write partial false for any relay that was
                // never resolved (neither sweep nor completePendingPublish ran for it).
                // Find unresolved relays by diffing the ok map against pendingPublishes.
                foreach ($this->pendingPublishes as $pendingKey => $pending) {
                    if ($pending['correlationId'] !== $correlationId || $pending['done']) {
                        continue;
                    }
                    $relayUrl = $pending['relayUrl'];
                    if (!isset($state['ok'][$relayUrl])) {
                        $state['ok'][$relayUrl] = false;
                        $state['done']++;
                        $this->pendingPublishes[$pendingKey]['done'] = true;
                        $this->writePartialPublishResponse(
                            $correlationId,
                            $relayUrl,
                            false,
                            'Correlation deadline expired',
                            $state['done'] >= $state['total'],
                        );
                    }
                }
                // Update tracking state so cleanup is coherent
                $this->pendingCorrelations[$correlationId] = $state;
            } else {
                // Query correlation — write the final response
                $this->writeResponse($correlationId, $state['events'], $state['errors']);
            }

            $this->cleanupCorrelation($correlationId);
        }
    }

    /**
     * Remove all pending-query and pending-publish entries for a correlationId.
     */
    private function cleanupCorrelation(string $correlationId): void
    {
        unset($this->pendingCorrelations[$correlationId]);

        foreach ($this->pendingQueries as $subId => $pending) {
            if ($pending['correlationId'] === $correlationId) {
                unset($this->pendingQueries[$subId]);
                unset($this->pendingAuthReqs[$subId]);
            }
        }

        foreach ($this->pendingPublishes as $pendingKey => $pending) {
            if ($pending['correlationId'] === $correlationId) {
                unset($this->pendingPublishes[$pendingKey]);
                unset($this->pendingAuthPublishes[$pendingKey]);
            }
        }
    }

    // =========================================================================
    // AUTH handling
    // =========================================================================

    private function handleAuthChallenge(GatewayConnection $conn, string $challenge): void
    {
        $key = $conn->getKey();

        // For shared connections, use ephemeral AUTH (current behavior)
        if ($conn->isShared()) {
            $this->logger->debug('Gateway: ephemeral AUTH for shared connection', [
                'relay' => $conn->relayUrl,
            ]);
            $this->handler->handleAuth($conn->relay, $conn->getClient(), $challenge);
            $conn->authStatus = 'authed';
            $this->healthStore->setAuthRequired($conn->relayUrl);
            $this->healthStore->setAuthStatus($conn->relayUrl, 'ephemeral');
            return;
        }

        // For user connections, initiate Mercure roundtrip
        $this->logger->info('Gateway: AUTH challenge for user connection, starting Mercure roundtrip', [
            'relay' => $conn->relayUrl,
            'pubkey' => substr($conn->pubkey, 0, 8) . '...',
        ]);

        $requestId = Uuid::v4()->toRfc4122();
        $conn->authStatus = 'pending';

        // The relay URL we publish to the browser must be the *public* hostname
        // (e.g. wss://relay.decentnewsroom.com), not the internal Docker address
        // (ws://strfry:7777). The browser builds a kind-22242 event with a
        // ["relay", <url>] tag; the relay validates that tag against its
        // own identity. We expose internal hostnames to the user's browser only
        // if the public URL isn't configured (safer fallback than silently dropping).
        $authRelayUrl = $this->resolveRelayUrlForAuth($conn->relayUrl);

        // Store pending challenge in Redis
        try {
            $this->redis->set(
                self::AUTH_PENDING_PREFIX . $requestId,
                json_encode([
                    'relay' => $authRelayUrl,
                    'challenge' => $challenge,
                    'pubkey' => $conn->pubkey,
                    'created_at' => time(),
                ]),
                ['ex' => $this->authTimeout],
            );

            // Publish to Mercure — browser receives the public URL
            $update = new Update(
                '/relay-auth/' . $conn->pubkey,
                json_encode([
                    'requestId' => $requestId,
                    'relay' => $authRelayUrl,
                    'challenge' => $challenge,
                ]),
            );
            $this->hub->publish($update);

            // Track pending AUTH
            $this->pendingAuths[$requestId] = [
                'connKey' => $key,
                'requestId' => $requestId,
                'startedAt' => time(),
            ];

            $this->logger->info('Gateway: AUTH challenge published to Mercure', [
                'request_id' => $requestId,
                'relay' => $conn->relayUrl,
                'pubkey' => substr($conn->pubkey, 0, 8) . '...',
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Gateway: failed to initiate AUTH roundtrip', [
                'relay' => $conn->relayUrl,
                'error' => $e->getMessage(),
            ]);
            $conn->authStatus = 'failed';
        }

        $this->healthStore->setAuthRequired($conn->relayUrl);
        $this->healthStore->setAuthStatus($conn->relayUrl, 'pending');
    }

    private function checkPendingAuths(): void
    {
        foreach ($this->pendingAuths as $requestId => $pending) {
            $connKey = $pending['connKey'];
            $startedAt = $pending['startedAt'];

            // Check for timeout
            if (time() - $startedAt > $this->authTimeout) {
                $this->logger->warning('Gateway: AUTH roundtrip timed out', [
                    'request_id' => $requestId,
                ]);
                if (isset($this->connections[$connKey])) {
                    $this->connections[$connKey]->authStatus = 'failed';
                }
                unset($this->pendingAuths[$requestId]);
                continue;
            }

            // Check if signed event has arrived
            try {
                $signedJson = $this->redis->get(self::AUTH_SIGNED_PREFIX . $requestId);
                if ($signedJson === false) {
                    continue; // Not yet
                }

                $signedEvent = json_decode($signedJson, true);
                if (!$signedEvent) {
                    unset($this->pendingAuths[$requestId]);
                    continue;
                }

                // We have a signed event — send AUTH to relay
                $conn = $this->connections[$connKey] ?? null;
                if ($conn && $conn->isConnected()) {
                    $authPayload = json_encode(['AUTH', $signedEvent]);
                    $conn->getClient()->text($authPayload);
                    $conn->authStatus = 'authed';
                    $conn->touch();

                    $this->healthStore->setAuthStatus($conn->relayUrl, 'user_authed');

                    $this->logger->info('Gateway: AUTH completed via Mercure roundtrip', [
                        'request_id' => $requestId,
                        'relay' => $conn->relayUrl,
                        'pubkey' => substr($conn->pubkey ?? '', 0, 8) . '...',
                    ]);

                    $this->flushDeferredForConnection($connKey, $conn);
                }

                // Clean up
                $this->redis->del(self::AUTH_SIGNED_PREFIX . $requestId);
                unset($this->pendingAuths[$requestId]);

            } catch (\RedisException $e) {
                $this->logger->debug('Gateway: Redis error checking pending AUTH', ['error' => $e->getMessage()]);
            }
        }

        // Promote connections that have been open for > 1 second with no AUTH
        // challenge to 'authed'. This means the relay doesn't require AUTH for
        // this connection and future publishes/queries go straight through
        // without being deferred. Applies to both user and on-demand connections.
        $now = time();
        foreach ($this->connections as $connKey => $conn) {
            if ($conn->authStatus !== 'none') {
                continue;
            }
            $connAge = $now - $conn->connectedAt;
            if ($connAge >= 1) {
                $conn->authStatus = 'authed';
                $this->logger->info('Gateway: connection settle — marking as authed (no AUTH challenge received)', [
                    'relay'    => $conn->relayUrl,
                    'pubkey'   => $conn->pubkey ? substr($conn->pubkey, 0, 8) . '...' : 'on-demand',
                    'conn_age' => $connAge,
                ]);
                // Flush any publishes/queries that were deferred waiting for this
                $this->flushDeferredForConnection($connKey, $conn);
            }
        }

        // Flush any remaining deferred publishes (belt-and-suspenders for cases
        // where flushDeferredForConnection was not triggered above).
        foreach ($this->pendingAuthPublishes as $pendingKey => $deferred) {
            $conn = $this->connections[$deferred['connKey']] ?? null;
            if (!$conn || !$conn->isConnected()) {
                unset($this->pendingAuthPublishes[$pendingKey]);
                continue;
            }

            if ($conn->authStatus === 'pending' || $conn->authStatus === 'failed') {
                continue;
            }

            // authStatus is 'none' but connAge < 1 — still in settle window
            if ($conn->authStatus === 'none' && ($now - $conn->connectedAt) < 1) {
                continue;
            }

            // Safe to send
            if (!isset($this->pendingPublishes[$pendingKey]) || $this->pendingPublishes[$pendingKey]['done']) {
                unset($this->pendingAuthPublishes[$pendingKey]);
                continue;
            }

            try {
                $conn->getClient()->text($deferred['payload']);
                $conn->touch();
                $this->logger->info('Gateway: sent deferred EVENT publish (no AUTH required)', [
                    'relay'    => $conn->relayUrl,
                    'event_id' => substr($deferred['eventId'], 0, 16),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Gateway: failed to send deferred EVENT publish', [
                    'relay' => $conn->relayUrl,
                    'error' => $e->getMessage(),
                ]);
                // Mark as failed and write partial so the client isn't left waiting
                $correlationId = $this->pendingPublishes[$pendingKey]['correlationId'];
                $relayUrl      = $this->pendingPublishes[$pendingKey]['relayUrl'];
                $this->pendingPublishes[$pendingKey]['done'] = true;
                if (isset($this->pendingCorrelations[$correlationId])) {
                    $this->pendingCorrelations[$correlationId]['ok'][$relayUrl] = false;
                    $total = $this->pendingCorrelations[$correlationId]['total'];
                    $done  = ++$this->pendingCorrelations[$correlationId]['done'];
                    $this->writePartialPublishResponse($correlationId, $relayUrl, false, $e->getMessage(), $done >= $total);
                }
            }
            unset($this->pendingAuthPublishes[$pendingKey]);
        }
    }


    // =========================================================================
    // Connection routing
    // =========================================================================

    /**
     * Re-send all deferred REQs and EVENT publishes that were waiting for AUTH
     * to complete on the given connection. Called after both the Mercure-signed
     * AUTH path and the "no AUTH required" settle-window path.
     */
    private function flushDeferredForConnection(string $connKey, GatewayConnection $conn): void
    {
        // Deferred REQs
        foreach ($this->pendingAuthReqs as $subId => $deferred) {
            if ($deferred['connKey'] !== $connKey) {
                continue;
            }
            if (isset($this->pendingQueries[$subId]) && !$this->pendingQueries[$subId]['done']) {
                try {
                    $conn->getClient()->text($deferred['payload']);
                    $conn->touch();
                    $this->logger->info('Gateway: sent deferred REQ (connection ready)', [
                        'subscription_id' => $subId,
                        'relay'           => $conn->relayUrl,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Gateway: failed to send deferred REQ', [
                        'subscription_id' => $subId,
                        'error'           => $e->getMessage(),
                    ]);
                    $this->pendingQueries[$subId]['done'] = true;
                    $correlationId = $this->pendingQueries[$subId]['correlationId'];
                    if (isset($this->pendingCorrelations[$correlationId])) {
                        $this->pendingCorrelations[$correlationId]['done']++;
                    }
                }
            }
            unset($this->pendingAuthReqs[$subId]);
        }

        // Deferred EVENT publishes
        foreach ($this->pendingAuthPublishes as $pendingKey => $deferred) {
            if ($deferred['connKey'] !== $connKey) {
                continue;
            }
            if (isset($this->pendingPublishes[$pendingKey]) && !$this->pendingPublishes[$pendingKey]['done']) {
                try {
                    $conn->getClient()->text($deferred['payload']);
                    $conn->touch();
                    $this->logger->info('Gateway: sent deferred EVENT publish (connection ready)', [
                        'pending_key' => $pendingKey,
                        'relay'       => $conn->relayUrl,
                        'event_id'    => substr($deferred['eventId'], 0, 16),
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('Gateway: failed to send deferred EVENT publish', [
                        'pending_key' => $pendingKey,
                        'error'       => $e->getMessage(),
                    ]);
                    $this->pendingPublishes[$pendingKey]['done'] = true;
                    $correlationId = $this->pendingPublishes[$pendingKey]['correlationId'];
                    $relayUrl      = $this->pendingPublishes[$pendingKey]['relayUrl'];
                    if (isset($this->pendingCorrelations[$correlationId])) {
                        $this->pendingCorrelations[$correlationId]['ok'][$relayUrl] = false;
                        $this->pendingCorrelations[$correlationId]['done']++;
                    }
                }
            }
            unset($this->pendingAuthPublishes[$pendingKey]);
        }
    }


    /**
     * Route to the best available connection for a query (REQ).
     *
     * Preference:
     *   1. User connection (if pubkey provided and connection exists + connected)
     *   2. On-demand shared connection (if exists + connected)
     *   3. Enqueue on-demand connection open → return null (caller defers REQ)
     */
    private function routeConnection(string $relayUrl, ?string $pubkey): ?GatewayConnection
    {
        // Try user connection first
        if ($pubkey) {
            $userKey = GatewayConnection::buildKey($relayUrl, $pubkey);
            if (isset($this->connections[$userKey]) && $this->connections[$userKey]->isConnected()) {
                return $this->connections[$userKey];
            }
        }

        // Try existing on-demand shared connection
        $sharedKey = GatewayConnection::buildKey($relayUrl);
        if (isset($this->connections[$sharedKey]) && $this->connections[$sharedKey]->isConnected()) {
            return $this->connections[$sharedKey];
        }

        // No connection available — enqueue an on-demand connection open.
        // drainPendingConnections() will open it on the next tick. The caller
        // defers the REQ into pendingAuthReqs so it's sent once connected.
        $this->enqueueOnDemandConnection($relayUrl);

        $this->logger->debug('Gateway: no connection available, enqueued on-demand open', [
            'relay'  => $relayUrl,
            'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
        ]);
        return null;
    }

    /**
     * Route to the best available connection for a publish.
     *
     * Preference:
     *   1. Existing user connection (authed or mid-AUTH → caller defers send)
     *   2. Existing on-demand shared connection
     *   3. Enqueue on-demand connection open → return null (caller defers EVENT)
     */
    private function routeConnectionForPublish(string $relayUrl, ?string $pubkey): ?GatewayConnection
    {
        if ($pubkey) {
            $userKey = GatewayConnection::buildKey($relayUrl, $pubkey);
            if (isset($this->connections[$userKey]) && $this->connections[$userKey]->isConnected()) {
                return $this->connections[$userKey];
            }
        }

        // Fall back to on-demand shared connection
        $sharedKey = GatewayConnection::buildKey($relayUrl);
        if (isset($this->connections[$sharedKey]) && $this->connections[$sharedKey]->isConnected()) {
            return $this->connections[$sharedKey];
        }

        // No connection — enqueue on-demand open. The caller will write an
        // immediate partial failure for this relay; the next publish attempt
        // (after connections open) will succeed.
        $this->enqueueOnDemandConnection($relayUrl);

        $this->logger->debug('Gateway: no connection available for publish, enqueued on-demand open', [
            'relay'  => $relayUrl,
            'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
        ]);
        return null;
    }

    // =========================================================================
    // Response writing
    // =========================================================================

    private function writeResponse(string $correlationId, array $events, array $errors): void
    {
        try {
            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            $this->redis->xAdd($responseKey, '*', [
                'events' => json_encode($events),
                'errors' => json_encode($errors),
                'eose'   => 'true',
            ]);
            $this->redis->expire($responseKey, 60);
        } catch (\RedisException $e) {
            $this->logger->error('Gateway: failed to write query response', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write a partial batch of events to the response stream without closing it
     * (eose:false). Called by completeQuery() as each relay finishes so the
     * client can start reading events from fast relays immediately.
     */
    private function writePartialResponse(string $correlationId, array $events): void
    {
        try {
            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            $this->redis->xAdd($responseKey, '*', [
                'events' => json_encode($events),
                'errors' => json_encode([]),
                'eose'   => 'false',
            ]);
            // Don't set expire here — writeResponse will set it with eose:true
        } catch (\RedisException $e) {
            $this->logger->debug('Gateway: failed to write partial response', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write a per-relay publish result to the response stream immediately.
     *
     * Each relay entry is written as soon as it resolves (OK received, timed out,
     * or connection failed) so the client can record the relay's result without
     * waiting for slower or stalled relays. The client stops reading once it has
     * collected results for all expected relays OR sees done:true.
     *
     * @param bool   $accepted  Whether the relay confirmed the event (OK true)
     * @param string $message   Reason string (empty on success, error/timeout on failure)
     * @param bool   $allDone   True when this is the last relay for this correlation
     */
    private function writePartialPublishResponse(
        string $correlationId,
        string $relayUrl,
        bool   $accepted,
        string $message,
        bool   $allDone,
    ): void {
        try {
            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            $this->redis->xAdd($responseKey, '*', [
                'relay'    => $relayUrl,
                'ok'       => $accepted ? 'true' : 'false',
                'message'  => $message,
                'done'     => $allDone ? 'true' : 'false',
            ]);
            if ($allDone) {
                $this->redis->expire($responseKey, 60);
            }
        } catch (\RedisException $e) {
            $this->logger->error('Gateway: failed to write partial publish response', [
                'correlation_id' => $correlationId,
                'relay' => $relayUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function writePublishResponse(string $correlationId, array $ok, array $errors): void
    {
        try {
            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            $this->redis->xAdd($responseKey, '*', [
                'ok'     => json_encode($ok),
                'errors' => json_encode($errors),
                'done'   => 'true',
            ]);
            $this->redis->expire($responseKey, 60);
        } catch (\RedisException $e) {
            $this->logger->error('Gateway: failed to write publish response', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // Maintenance
    // =========================================================================

    private function performMaintenance(): void
    {
        // Close idle OR dead user connections
        foreach ($this->connections as $key => $conn) {
            if (!$conn->isUserConnection()) {
                continue;
            }
            if (!$conn->isConnected()) {
                $this->logger->info('Gateway: closing dead user connection', [
                    'relay'  => $conn->relayUrl,
                    'pubkey' => substr($conn->pubkey, 0, 8) . '...',
                ]);
                $this->closeConnection($key);
                continue;
            }
            if ($conn->getIdleSeconds() > $this->userIdleTimeout) {
                $this->logger->info('Gateway: closing idle user connection', [
                    'relay'        => $conn->relayUrl,
                    'pubkey'       => substr($conn->pubkey, 0, 8) . '...',
                    'idle_seconds' => $conn->getIdleSeconds(),
                ]);
                $this->closeConnection($key);
            }
        }

        // Close idle or dead on-demand shared connections.
        // On-demand connections are opened lazily and closed after the idle TTL.
        // No reconnection — they'll be re-opened when next needed.
        foreach ($this->connections as $key => $conn) {
            if (!$conn->isShared()) {
                continue;
            }
            if (!$conn->isConnected()) {
                $this->logger->debug('Gateway: removing dead on-demand connection', [
                    'relay' => $conn->relayUrl,
                ]);
                $this->closeConnection($key);
                continue;
            }
            if ($conn->getIdleSeconds() > $this->onDemandIdleTimeout) {
                $this->logger->info('Gateway: closing idle on-demand connection', [
                    'relay'        => $conn->relayUrl,
                    'idle_seconds' => $conn->getIdleSeconds(),
                ]);
                $this->closeConnection($key);
            }
        }

        // Log stats
        $sharedCount = 0;
        $userCount = 0;
        foreach ($this->connections as $conn) {
            if ($conn->isShared()) {
                $sharedCount++;
            } else {
                $userCount++;
            }
        }

        $this->logger->info('Gateway: maintenance complete', [
            'on_demand_connections' => $sharedCount,
            'user_connections'      => $userCount,
            'pending_auths'         => count($this->pendingAuths),
            'pending_queries'       => count($this->pendingQueries),
            'pending_publishes'     => count($this->pendingPublishes),
            'pending_correlations'  => count($this->pendingCorrelations),
            'pending_connections'   => count($this->pendingConnections),
            'deferred_publishes'    => count($this->pendingAuthPublishes),
            'deferred_queries'      => count($this->pendingAuthReqs),
            'last_request_id'       => $this->lastRequestId,
            'last_control_id'       => $this->lastControlId,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function countUserConnections(string $pubkey): int
    {
        $count = 0;
        foreach ($this->connections as $conn) {
            if ($conn->pubkey === $pubkey) {
                $count++;
            }
        }
        return $count;
    }

    private function countAllUserConnections(): int
    {
        $count = 0;
        foreach ($this->connections as $conn) {
            if ($conn->isUserConnection()) {
                $count++;
            }
        }
        return $count;
    }

    private function evictIdlestUserConnection(): void
    {
        $idlest = null;
        $idlestKey = null;
        $maxIdle = 0;

        foreach ($this->connections as $key => $conn) {
            if ($conn->isUserConnection() && $conn->getIdleSeconds() > $maxIdle) {
                $maxIdle = $conn->getIdleSeconds();
                $idlest = $conn;
                $idlestKey = $key;
            }
        }

        if ($idlestKey) {
            $this->logger->info('Gateway: evicting idlest user connection', [
                'key' => $idlestKey,
                'idle_seconds' => $maxIdle,
            ]);
            $this->closeConnection($idlestKey);
        }
    }

    private function evictIdlestSharedConnection(): void
    {
        $idlestKey = null;
        $maxIdle = 0;

        foreach ($this->connections as $key => $conn) {
            if ($conn->isShared() && $conn->getIdleSeconds() > $maxIdle) {
                $maxIdle = $conn->getIdleSeconds();
                $idlestKey = $key;
            }
        }

        if ($idlestKey) {
            $this->logger->info('Gateway: evicting idlest on-demand connection to make room', [
                'key' => $idlestKey,
                'idle_seconds' => $maxIdle,
            ]);
            $this->closeConnection($idlestKey);
        }
    }

    private function isTimeoutError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'empty read');
    }
}

