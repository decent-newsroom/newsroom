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
use swentel\nostr\RelayResponse\RelayResponse;
use swentel\nostr\Subscription\Subscription;
use WebSocket\Message\Ping;
use WebSocket\Message\Text;

/**
 * Long-lived relay gateway process.
 *
 * Maintains persistent WebSocket connections to external Nostr relays,
 * handles NIP-42 AUTH via Mercure roundtrip signing, and serves as the
 * single point of relay communication for all FrankenPHP request workers.
 *
 * Communication protocol:
 *   relay:requests       — query/publish requests from request workers (Redis Streams)
 *   relay:control        — lifecycle commands: warm, close (Redis Streams)
 *   relay:responses:{id} — per-correlation-ID response streams
 *
 * Connection model:
 *   Shared:  keyed by relay URL — one per relay, ephemeral AUTH
 *   User:    keyed by relay::pubkey — one per (relay, user), authed as user's npub
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
     *   deadline: int,
     *   done: bool,
     * }>
     */
    private array $pendingPublishes = [];

    /**
     * Pending AUTH challenges waiting for signed events.
     * @var array<string, array{connKey: string, requestId: string, startedAt: int}>
     */
    private array $pendingAuths = [];

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

    protected function configure(): void
    {
        $this
            ->addOption('max-user-conns', null, InputOption::VALUE_OPTIONAL, 'Max connections per user', '5')
            ->addOption('max-total-user-conns', null, InputOption::VALUE_OPTIONAL, 'Max total user connections', '200')
            ->addOption('max-shared-conns', null, InputOption::VALUE_OPTIONAL, 'Max shared connections', '20')
            ->addOption('user-idle-timeout', null, InputOption::VALUE_OPTIONAL, 'User connection idle timeout (seconds)', '1800')
            ->addOption('auth-timeout', null, InputOption::VALUE_OPTIONAL, 'AUTH roundtrip timeout (seconds)', '60');
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
        $this->authTimeout = (int) $input->getOption('auth-timeout');

        // Register signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }

        $this->handler = new RelaySubscriptionHandler($this->logger);

        // Initialize streams (create if not exist)
        $this->initializeStreams();

        // Open shared connections from RelayRegistry
        $this->openSharedConnections($io);

        $io->success(sprintf('Gateway started with %d shared connections. Entering event loop.', count($this->connections)));

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

                // 2. Read from all open WebSocket connections (non-blocking)
                $this->processWebSocketMessages();

                // 3. Check for completed AUTH roundtrips
                $this->checkPendingAuths();

                // 4. Periodic maintenance (every 60 seconds)
                if (time() - $lastMaintenanceCheck >= 60) {
                    $this->performMaintenance();
                    $lastMaintenanceCheck = time();
                }

            } catch (\Throwable $e) {
                $this->logger->error('Gateway event loop error', [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                ]);
                // Don't crash — sleep briefly and continue
                usleep(100_000); // 100ms
            }

            // Small sleep to prevent CPU spin
            usleep(50_000); // 50ms
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
        try {
            // Ensure streams exist (XADD a dummy and immediately trim, or just let first XADD create them)
            // Read from latest — we don't want to replay old messages on restart
            $this->lastRequestId = '$';
            $this->lastControlId = '$';
        } catch (\RedisException $e) {
            $this->logger->warning('Gateway: failed to initialize streams', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // Shared connection management
    // =========================================================================

    private function openSharedConnections(SymfonyStyle $io): void
    {
        $relayUrls = array_merge(
            $this->relayRegistry->getContentRelays(),
            $this->relayRegistry->getProfileRelays(),
        );
        $relayUrls = array_unique($relayUrls);

        // Respect max shared connections limit
        $relayUrls = array_slice($relayUrls, 0, $this->maxSharedConnections);

        foreach ($relayUrls as $url) {
            try {
                $this->openConnection($url, null);
                $io->writeln(sprintf('  ✓ Shared: <info>%s</info>', $url));
            } catch (\Throwable $e) {
                $io->writeln(sprintf('  ✗ Shared: <error>%s</error> — %s', $url, $e->getMessage()));
            }
        }
    }

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
            'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : 'shared',
        ]);

        try {
            // Use swentel Relay (wraps WebSocket\Client) — same as TweakedRequest
            $relay = new Relay($relayUrl);
            $relay->connect();

            $client = $relay->getClient();
            $client->setTimeout(1); // Non-blocking reads in event loop

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
                'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : 'shared',
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
            'pubkey' => $conn->pubkey ? substr($conn->pubkey, 0, 8) . '...' : 'shared',
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
            $messages = $this->redis->xRead(
                [self::REQUEST_STREAM => $this->lastRequestId],
                5, // batch size
                100, // block 100ms
            );

            if (!$messages || !isset($messages[self::REQUEST_STREAM])) {
                return;
            }

            foreach ($messages[self::REQUEST_STREAM] as $messageId => $data) {
                $this->lastRequestId = $messageId;
                $this->handleRequest($data);
            }

            // Trim old messages (keep last 1000)
            $this->redis->xTrim(self::REQUEST_STREAM, 1000);

        } catch (\RedisException $e) {
            $this->logger->debug('Gateway: Redis read error on request stream', ['error' => $e->getMessage()]);
        }
    }

    private function processControlStream(): void
    {
        try {
            $messages = $this->redis->xRead(
                [self::CONTROL_STREAM => $this->lastControlId],
                5,
                50, // block 50ms
            );

            if (!$messages || !isset($messages[self::CONTROL_STREAM])) {
                return;
            }

            foreach ($messages[self::CONTROL_STREAM] as $messageId => $data) {
                $this->lastControlId = $messageId;
                $this->handleControl($data);
            }

            $this->redis->xTrim(self::CONTROL_STREAM, 1000);

        } catch (\RedisException $e) {
            $this->logger->debug('Gateway: Redis read error on control stream', ['error' => $e->getMessage()]);
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

                if (!$conn || !$conn->isConnected()) {
                    $errors[$relayUrl] = 'No connection available';
                    continue;
                }

                // Build and send REQ
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

                $requestMessage = new RequestMessage($subscriptionId, [$filterObj]);
                $conn->getClient()->text($requestMessage->generate());
                $conn->touch();

                // Register in the pending-query table — processWebSocketMessages()
                // will dispatch EVENT/EOSE/CLOSED/NOTICE to this entry.
                $this->pendingQueries[$subscriptionId] = [
                    'correlationId' => $correlationId,
                    'relayUrl'      => $relayUrl,
                    'connKey'       => $conn->getKey(),
                    'events'        => [],
                    'deadline'      => $deadline,
                    'done'          => false,
                ];

                $registeredCount++;

            } catch (\Throwable $e) {
                $errors[$relayUrl] = $e->getMessage();
                $this->healthStore->recordFailure($relayUrl);
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

        $errors = [];
        $ok = [];
        $payload = json_encode(['EVENT', $event]);
        $deadline = time() + $timeout;
        $registeredCount = 0;

        foreach ($relayUrls as $relayUrl) {
            try {
                $conn = $this->routeConnection($relayUrl, $pubkey);

                if (!$conn || !$conn->isConnected()) {
                    $errors[$relayUrl] = 'No connection available';
                    continue;
                }

                $conn->getClient()->text($payload);
                $conn->touch();

                // Register for non-blocking OK tracking
                $pendingKey = $correlationId . '::' . $relayUrl;
                $this->pendingPublishes[$pendingKey] = [
                    'correlationId' => $correlationId,
                    'relayUrl'      => $relayUrl,
                    'connKey'       => $conn->getKey(),
                    'deadline'      => $deadline,
                    'done'          => false,
                ];

                $registeredCount++;

            } catch (\Throwable $e) {
                $errors[$relayUrl] = $e->getMessage();
                $this->healthStore->recordFailure($relayUrl);
            }
        }

        if ($registeredCount === 0) {
            // All relays failed immediately
            $this->writePublishResponse($correlationId, [], $errors);
        } else {
            // Store seed errors so they're included when the response is flushed
            if (!isset($this->pendingCorrelations[$correlationId])) {
                $this->pendingCorrelations[$correlationId] = [
                    'total'    => $registeredCount,
                    'done'     => 0,
                    'events'   => [], // not used for publish
                    'errors'   => $errors,
                    'deadline' => $deadline,
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

        $this->logger->info('Gateway: warming user connections', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
            'relay_count' => count($relayUrls),
        ]);

        // Enforce per-user limit
        $currentUserConns = $this->countUserConnections($pubkey);
        $remaining = $this->maxConnectionsPerUser - $currentUserConns;
        $relayUrls = array_slice($relayUrls, 0, max(0, $remaining));

        // Enforce total user connection limit
        $totalUserConns = $this->countAllUserConnections();
        if ($totalUserConns >= $this->maxTotalUserConnections) {
            $this->evictIdlestUserConnection();
        }

        foreach ($relayUrls as $relayUrl) {
            try {
                $this->openConnection($relayUrl, $pubkey);
            } catch (\Throwable $e) {
                $this->logger->warning('Gateway: failed to warm user connection', [
                    'relay' => $relayUrl,
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
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
                        // Match to a pending publish by scanning for this connection
                        $accepted = ($decoded[2] ?? false) === true;
                        $this->completePendingPublish($conn, $accepted);
                        break;

                    case 'CLOSED':
                    case 'NOTICE':
                        // Treat relay-initiated CLOSED as EOSE for any pending query on this connection
                        $subId = $decoded[1] ?? null;
                        if ($subId && isset($this->pendingQueries[$subId]) && !$this->pendingQueries[$subId]['done']) {
                            $this->completeQuery($subId, $conn);
                        }
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
     * Mark a pending query subscription as done and merge its events into the
     * parent correlation. Sends CLOSE to the relay.
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

        // Merge into correlation
        $correlationId = $pending['correlationId'];
        if (isset($this->pendingCorrelations[$correlationId])) {
            $this->pendingCorrelations[$correlationId]['events'] = array_merge(
                $this->pendingCorrelations[$correlationId]['events'],
                $pending['events'],
            );
            $this->pendingCorrelations[$correlationId]['done']++;
        }
    }

    /**
     * Find the oldest incomplete pending publish on the given connection and
     * mark it done with the given OK status.
     */
    private function completePendingPublish(GatewayConnection $conn, bool $accepted): void
    {
        foreach ($this->pendingPublishes as $pendingKey => &$pending) {
            if ($pending['done'] || $pending['connKey'] !== $conn->getKey()) {
                continue;
            }

            $pending['done'] = true;
            $relayUrl = $pending['relayUrl'];
            $correlationId = $pending['correlationId'];

            if ($accepted) {
                $this->healthStore->recordSuccess($relayUrl);
            }

            // Store OK result in the correlation's events array (reusing as ok map)
            if (isset($this->pendingCorrelations[$correlationId])) {
                // We embed ok results in events as a sentinel; writePublishResponse
                // reads from pendingCorrelations['ok'] if present.
                $this->pendingCorrelations[$correlationId]['ok'][$relayUrl] = $accepted;
                $this->pendingCorrelations[$correlationId]['done']++;
            }

            return; // Only resolve the first matching pending publish per OK message
        }
        unset($pending);
    }

    /**
     * For each correlationId where all subscriptions/publishes are done,
     * write the response stream and clean up.
     */
    private function flushCompletedCorrelations(): void
    {
        foreach ($this->pendingCorrelations as $correlationId => $state) {
            if ($state['done'] < $state['total']) {
                continue;
            }

            if (isset($state['ok'])) {
                // Publish response
                $this->writePublishResponse($correlationId, $state['ok'], $state['errors']);
            } else {
                // Query response
                $this->writeResponse($correlationId, $state['events'], $state['errors']);
            }

            $this->cleanupCorrelation($correlationId);
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
            }
        }

        // Now flush any correlations that became complete due to timeouts
        $this->flushCompletedCorrelations();

        // Also force-flush correlations whose deadline has expired (edge case:
        // a relay never sends EOSE and the subscription deadline has passed).
        foreach ($this->pendingCorrelations as $correlationId => $state) {
            if ($state['deadline'] <= $now) {
                $this->logger->debug('Gateway: force-flushing expired correlation', [
                    'correlation_id' => $correlationId,
                    'done' => $state['done'],
                    'total' => $state['total'],
                ]);

                if (isset($state['ok'])) {
                    $this->writePublishResponse($correlationId, $state['ok'], $state['errors']);
                } else {
                    $this->writeResponse($correlationId, $state['events'], $state['errors']);
                }

                $this->cleanupCorrelation($correlationId);
            }
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
            }
        }

        foreach ($this->pendingPublishes as $pendingKey => $pending) {
            if ($pending['correlationId'] === $correlationId) {
                unset($this->pendingPublishes[$pendingKey]);
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

        // Store pending challenge in Redis
        try {
            $this->redis->set(
                self::AUTH_PENDING_PREFIX . $requestId,
                json_encode([
                    'relay' => $conn->relayUrl,
                    'challenge' => $challenge,
                    'pubkey' => $conn->pubkey,
                    'created_at' => time(),
                ]),
                ['ex' => $this->authTimeout],
            );

            // Publish to Mercure
            $update = new Update(
                '/relay-auth/' . $conn->pubkey,
                json_encode([
                    'requestId' => $requestId,
                    'relay' => $conn->relayUrl,
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
                }

                // Clean up
                $this->redis->del(self::AUTH_SIGNED_PREFIX . $requestId);
                unset($this->pendingAuths[$requestId]);

            } catch (\RedisException $e) {
                $this->logger->debug('Gateway: Redis error checking pending AUTH', ['error' => $e->getMessage()]);
            }
        }
    }

    // =========================================================================
    // Connection routing
    // =========================================================================

    /**
     * Route to the best available connection for a relay + optional pubkey.
     *
     * Preference:
     *   1. User connection (if pubkey provided and connection exists + authed)
     *   2. Shared connection
     *   3. Open new shared connection on demand
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

        // Try shared connection
        $sharedKey = GatewayConnection::buildKey($relayUrl);
        if (isset($this->connections[$sharedKey]) && $this->connections[$sharedKey]->isConnected()) {
            return $this->connections[$sharedKey];
        }

        // Open a new shared connection on demand
        try {
            return $this->openConnection($relayUrl, null);
        } catch (\Throwable) {
            return null;
        }
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

    private function writePublishResponse(string $correlationId, array $ok, array $errors): void
    {
        try {
            $responseKey = self::RESPONSE_PREFIX . $correlationId;
            $this->redis->xAdd($responseKey, '*', [
                'ok'     => json_encode($ok),
                'errors' => json_encode($errors),
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
        // Close idle user connections
        foreach ($this->connections as $key => $conn) {
            if ($conn->isUserConnection() && $conn->getIdleSeconds() > $this->userIdleTimeout) {
                $this->logger->info('Gateway: closing idle user connection', [
                    'relay' => $conn->relayUrl,
                    'pubkey' => substr($conn->pubkey, 0, 8) . '...',
                    'idle_seconds' => $conn->getIdleSeconds(),
                ]);
                $this->closeConnection($key);
            }
        }

        // Reconnect dropped shared connections (with backoff)
        foreach ($this->connections as $key => $conn) {
            if ($conn->isShared() && !$conn->isConnected()) {
                // Respect exponential backoff
                $delay = $conn->getReconnectDelay();
                $timeSinceLastAttempt = time() - $conn->lastActivity;
                if ($timeSinceLastAttempt < $delay) {
                    continue; // Too soon to retry
                }

                $conn->reconnectAttempts++;
                $this->logger->info('Gateway: reconnecting dropped shared connection', [
                    'relay' => $conn->relayUrl,
                    'attempt' => $conn->reconnectAttempts,
                    'next_delay' => $conn->getReconnectDelay(),
                ]);
                try {
                    // Remove the old entry, openConnection will create a new one
                    unset($this->connections[$key]);
                    $this->openConnection($conn->relayUrl, null);
                } catch (\Throwable) {
                    // Re-insert with bumped reconnect attempts so backoff increases
                    $conn->lastActivity = time();
                    $this->connections[$key] = $conn;
                }
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
            'shared_connections'   => $sharedCount,
            'user_connections'     => $userCount,
            'pending_auths'        => count($this->pendingAuths),
            'pending_queries'      => count($this->pendingQueries),
            'pending_publishes'    => count($this->pendingPublishes),
            'pending_correlations' => count($this->pendingCorrelations),
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

    private function isTimeoutError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'timeout')
            || str_contains($msg, 'timed out')
            || str_contains($msg, 'empty read');
    }
}

