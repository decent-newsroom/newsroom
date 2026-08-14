<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Command;

use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayActivityRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayFilterStatsRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayHealthRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\RelayUrlResolverInterface;
use DecentNewsroom\RelayGatewayBundle\Service\CollectingEventHandler;
use DecentNewsroom\RelayGatewayBundle\Service\GatewayAuthChallengeHandler;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Amp\delay;

#[AsCommand(
    name: 'app:relay-gateway',
    description: 'Persistent Redis-stream Nostr relay gateway backed by nostr-client-bundle',
)]
final class RelayGatewayCommand extends Command
{
    private const REQUEST_STREAM = 'relay:requests';
    private const CONTROL_STREAM = 'relay:control';
    private const RESPONSE_PREFIX = 'relay:responses:';

    private bool $shouldStop = false;
    private string $lastRequestId = '$';
    private string $lastControlId = '$';
    private int $lastHeartbeatAt = 0;

    private int $runtimeAuthTimeoutSeconds = 60;
    private int $maxConnectionsPerUser = 5;
    private int $maxTotalUserConnections = 200;
    private int $maxSharedConnections = 50;
    private int $userIdleTimeoutSeconds = 7200;
    private int $onDemandIdleTimeoutSeconds = 300;

    /** @var array<string, array{client: NostrClientInterface, relay: RelayUrl, relay_url: string, pubkey: ?string, persistent: bool, created_at: int, last_used_at: int}> */
    private array $connections = [];

    /** @var array<string, true> */
    private array $persistentSharedConnectionKeys = [];

    public function __construct(
        private readonly \Redis $redis,
        private readonly NostrClientFactoryInterface $clientFactory,
        private readonly ConnectionConfig $connectionConfig,
        private readonly RelayUrlResolverInterface $relayUrlResolver,
        private readonly AuthChallengeSignerInterface $authChallengeSigner,
        private readonly GatewayHealthRecorderInterface $healthRecorder,
        private readonly GatewayActivityRecorderInterface $activityRecorder,
        private readonly GatewayFilterStatsRecorderInterface $filterStatsRecorder,
        private readonly LoggerInterface $logger,
        private readonly int $streamBlockMs = 1000,
        private readonly int $responseTtlSeconds = 60,
        private readonly int $heartbeatTtlSeconds = 30,
        private readonly int $heartbeatIntervalSeconds = 5,
        private readonly int $authTimeoutSeconds = 60,
    ) {
        parent::__construct();
        $this->runtimeAuthTimeoutSeconds = $authTimeoutSeconds;
    }

    protected function configure(): void
    {
        $this
            ->addOption('time-limit', null, InputOption::VALUE_OPTIONAL, 'Max runtime in seconds before graceful restart (0=unlimited)', '3600')
            ->addOption('query-timeout', null, InputOption::VALUE_OPTIONAL, 'Default per-request query timeout when the request omits one', '15')
            ->addOption('publish-timeout', null, InputOption::VALUE_OPTIONAL, 'Default per-request publish timeout when the request omits one', '10')
            ->addOption('max-user-conns', null, InputOption::VALUE_OPTIONAL, 'Max open connections for one user pubkey', '5')
            ->addOption('max-total-user-conns', null, InputOption::VALUE_OPTIONAL, 'Max open user-keyed connections', '200')
            ->addOption('max-shared-conns', null, InputOption::VALUE_OPTIONAL, 'Max on-demand anonymous shared connections', '50')
            ->addOption('user-idle-timeout', null, InputOption::VALUE_OPTIONAL, 'User connection idle timeout (seconds)', '7200')
            ->addOption('on-demand-idle-timeout', null, InputOption::VALUE_OPTIONAL, 'On-demand shared connection idle timeout (seconds)', '300')
            ->addOption('prewarm-shared-relays', null, InputOption::VALUE_NONE, 'Open configured shared relay connections at startup; off by default to keep public connections sparse')
            ->addOption('auth-timeout', null, InputOption::VALUE_OPTIONAL, 'AUTH roundtrip timeout (seconds)', (string) $this->authTimeoutSeconds);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Relay Gateway');
        $io->text('Using decent-newsroom/nostr-client-bundle transport.');

        $this->applyRuntimeOptions($input);
        $this->initialiseStreams();
        $this->installSignalHandlers();
        $this->writeHeartbeat(true);
        if ((bool) $input->getOption('prewarm-shared-relays')) {
            $this->prewarmConfiguredRelays();
        }

        $timeLimit = max(0, (int) $input->getOption('time-limit'));
        $startedAt = time();

        while (!$this->shouldStop) {
            if ($timeLimit > 0 && time() - $startedAt >= $timeLimit) {
                $this->logger->info('RelayGatewayBundle: time limit reached, exiting gracefully');
                break;
            }

            $this->processRequestStream((int) $input->getOption('query-timeout'), (int) $input->getOption('publish-timeout'));
            $this->processControlStream();
            $this->performMaintenance();
            $this->writeHeartbeat();
        }

        $this->closeAllConnections();
        $this->writeHeartbeat(true);
        $io->success('Relay gateway stopped.');

        return Command::SUCCESS;
    }

    private function applyRuntimeOptions(InputInterface $input): void
    {
        $this->maxConnectionsPerUser = max(1, (int) $input->getOption('max-user-conns'));
        $this->maxTotalUserConnections = max(1, (int) $input->getOption('max-total-user-conns'));
        $this->maxSharedConnections = max(1, (int) $input->getOption('max-shared-conns'));
        $this->userIdleTimeoutSeconds = max(1, (int) $input->getOption('user-idle-timeout'));
        $this->onDemandIdleTimeoutSeconds = max(1, (int) $input->getOption('on-demand-idle-timeout'));
        $this->runtimeAuthTimeoutSeconds = max(1, (int) $input->getOption('auth-timeout'));
    }

    private function initialiseStreams(): void
    {
        $this->lastRequestId = $this->readCursor('requests') ?? '$';
        $this->lastControlId = $this->readCursor('control') ?? '$';

        $this->ensureStreamExists(self::REQUEST_STREAM, 'requests');
        $this->ensureStreamExists(self::CONTROL_STREAM, 'control');
    }

    private function ensureStreamExists(string $stream, string $cursorName): void
    {
        try {
            $this->redis->xInfo('STREAM', $stream);
        } catch (\RedisException) {
            $id = (string) $this->redis->xAdd($stream, '*', ['action' => 'init']);
            $this->writeCursor($cursorName, $id);
            if ($stream === self::REQUEST_STREAM) {
                $this->lastRequestId = $id;
            } else {
                $this->lastControlId = $id;
            }
        }
    }

    private function prewarmConfiguredRelays(): void
    {
        foreach ($this->relayUrlResolver->getPrewarmRelayUrls() as $relayUrl) {
            $connectionUrl = $this->relayUrlResolver->resolveToConnectionUrl($relayUrl);
            $this->persistentSharedConnectionKeys[$this->connectionKey($connectionUrl, null)] = true;

            try {
                $this->getConnection($connectionUrl, null, persistent: true);
            } catch (\Throwable $e) {
                $this->logger->warning('RelayGatewayBundle: configured relay prewarm failed', [
                    'relay' => $connectionUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processRequestStream(int $defaultQueryTimeout, int $defaultPublishTimeout): void
    {
        try {
            $messages = $this->redis->xRead([self::REQUEST_STREAM => $this->lastRequestId], 10, $this->streamBlockMs);
        } catch (\RedisException $e) {
            $this->logger->warning('RelayGatewayBundle: failed to read request stream', ['error' => $e->getMessage()]);
            delay(0.25);
            return;
        }

        if (!isset($messages[self::REQUEST_STREAM]) || !is_array($messages[self::REQUEST_STREAM])) {
            return;
        }

        foreach ($messages[self::REQUEST_STREAM] as $messageId => $data) {
            $this->lastRequestId = (string) $messageId;
            $this->writeCursor('requests', $this->lastRequestId);

            try {
                $this->handleRequest($data, $defaultQueryTimeout, $defaultPublishTimeout);
            } catch (\Throwable $e) {
                $this->logger->error('RelayGatewayBundle: request failed', [
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function processControlStream(): void
    {
        try {
            $messages = $this->redis->xRead([self::CONTROL_STREAM => $this->lastControlId], 10, 1);
        } catch (\RedisException $e) {
            $this->logger->warning('RelayGatewayBundle: failed to read control stream', ['error' => $e->getMessage()]);
            return;
        }

        if (!isset($messages[self::CONTROL_STREAM]) || !is_array($messages[self::CONTROL_STREAM])) {
            return;
        }

        foreach ($messages[self::CONTROL_STREAM] as $messageId => $data) {
            $this->lastControlId = (string) $messageId;
            $this->writeCursor('control', $this->lastControlId);
            $this->handleControl($data);
        }
    }

    /** @param array<string,string> $data */
    private function handleRequest(array $data, int $defaultQueryTimeout, int $defaultPublishTimeout): void
    {
        $action = (string) ($data['action'] ?? '');
        $correlationId = (string) ($data['id'] ?? '');

        if ($correlationId === '') {
            return;
        }

        if ($action === 'query') {
            $this->handleQuery($data, $correlationId, $defaultQueryTimeout);
            return;
        }

        if ($action === 'publish') {
            $this->handlePublish($data, $correlationId, $defaultPublishTimeout);
        }
    }

    /** @param array<string,string> $data */
    private function handleQuery(array $data, string $correlationId, int $defaultTimeout): void
    {
        $relayUrls = $this->decodeStringList($data['relays'] ?? '[]');
        $filters = $this->decodeFilters($data);
        $pubkey = $this->optionalString($data['pubkey'] ?? null);
        $timeout = max(1, (int) ($data['timeout'] ?? $defaultTimeout));

        $events = [];
        $errors = [];

        foreach ($relayUrls as $relayUrl) {
            $result = $this->queryRelay($relayUrl, $filters, $pubkey, $timeout);
            $events = array_merge($events, $result['events']);
            if ($result['error'] !== null) {
                $errors[$relayUrl] = $result['error'];
            }
        }

        $this->writeQueryResponse($correlationId, $events, $errors);
    }

    /**
     * @param list<array<string,mixed>> $filterData
     * @return array{events: list<array<string,mixed>>, error: ?string}
     */
    private function queryRelay(string $relayUrl, array $filterData, ?string $pubkey, int $timeout): array
    {
        $connectionUrl = $this->relayUrlResolver->resolveToConnectionUrl($relayUrl);
        $connectionKey = null;

        try {
            [$connectionKey, $client, $relay] = $this->getConnection(
                $connectionUrl,
                $pubkey,
                $pubkey === null && isset($this->persistentSharedConnectionKeys[$this->connectionKey($connectionUrl, null)]),
            );

            $requests = $this->expandFiltersForSequentialRequests($filterData);
            if (count($requests) > 1) {
                $this->logger->debug('RelayGatewayBundle: splitting relay query into sequential single-filter requests', [
                    'relay' => $connectionUrl,
                    'request_count' => count($requests),
                    'pubkey' => $pubkey !== null ? substr($pubkey, 0, 8) . '...' : null,
                ]);
            }

            $eventsById = [];
            $errors = [];
            $deadline = microtime(true) + $timeout;

            foreach ($requests as $requestFilter) {
                $remainingSeconds = $deadline - microtime(true);
                if ($remainingSeconds <= 0.0) {
                    $errors[] = 'timeout';
                    break;
                }

                $result = $this->queryRelayOnce($client, $relay, $connectionUrl, $requestFilter, $remainingSeconds);
                foreach ($result['events'] as $event) {
                    $eventKey = isset($event['id']) && is_string($event['id']) && $event['id'] !== ''
                        ? $event['id']
                        : hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($event));
                    $eventsById[$eventKey] = $event;
                }

                if ($result['error'] !== null) {
                    $errors[] = $result['error'];
                }

                if ($result['timed_out']) {
                    break;
                }
            }

            if ($connectionKey !== null) {
                $this->touchConnection($connectionKey);
            }

            $errors = array_values(array_unique(array_filter($errors, static fn(string $error): bool => $error !== '')));

            return [
                'events' => array_values($eventsById),
                'error' => $errors !== [] ? implode('; ', $errors) : null,
            ];
        } catch (\Throwable $e) {
            $this->healthRecorder->recordFailure($connectionUrl);
            if ($connectionKey !== null) {
                $this->closeConnection($connectionKey);
            }
            return ['events' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $filterData
     * @return array{events: list<array<string,mixed>>, error: ?string, timed_out: bool}
     */
    private function queryRelayOnce(NostrClientInterface $client, RelayUrl $relay, string $connectionUrl, array $filterData, float $timeoutSeconds): array
    {
        $signature = $this->filterStatsRecorder->signature($filterData);
        $this->filterStatsRecorder->recordRequest($connectionUrl, $signature);

        $handler = new CollectingEventHandler();
        $subscriptionId = null;
        $startedAt = microtime(true);

        try {
            $subscriptionId = $client->subscribe(
                $relay,
                Filter::fromArray($filterData),
                $handler,
                SubscriptionId::fromString('gw-' . bin2hex(random_bytes(6))),
            );

            $deadline = microtime(true) + max(0.1, $timeoutSeconds);
            while (!$handler->isDone() && microtime(true) < $deadline) {
                delay(0.05);
            }

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $events = $handler->events();
            foreach ($events as $event) {
                $this->healthRecorder->recordEventReceived($connectionUrl);
            }

            if ($handler->isDone()) {
                $error = $handler->error();
                if ($error !== null) {
                    $this->filterStatsRecorder->recordTimeout($connectionUrl, $signature);
                    $this->recordAuthRequiredIfNeeded($connectionUrl, $error);

                    return ['events' => $events, 'error' => $error, 'timed_out' => false];
                }

                $this->healthRecorder->recordSuccess($connectionUrl, $latencyMs);
                $this->filterStatsRecorder->recordEose($connectionUrl, $signature, $latencyMs, $handler->eventCount());

                return ['events' => $events, 'error' => null, 'timed_out' => false];
            }

            $this->filterStatsRecorder->recordTimeout($connectionUrl, $signature);

            return ['events' => $events, 'error' => 'timeout', 'timed_out' => true];
        } finally {
            if ($subscriptionId !== null) {
                try {
                    $client->unsubscribe($relay, $subscriptionId);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $filterData
     * @return list<array<string,mixed>>
     */
    private function expandFiltersForSequentialRequests(array $filterData): array
    {
        $requests = [];

        foreach ($filterData as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            if (!isset($filter['kinds']) || !is_array($filter['kinds'])) {
                $requests[] = $filter;
                continue;
            }

            $kinds = [];
            foreach ($filter['kinds'] as $kind) {
                if (is_int($kind)) {
                    $kinds[] = $kind;
                    continue;
                }

                if (is_string($kind) && is_numeric($kind)) {
                    $kinds[] = (int) $kind;
                }
            }

            $kinds = array_values(array_unique($kinds));
            sort($kinds, SORT_NUMERIC);

            if (count($kinds) <= 1) {
                if ($kinds !== []) {
                    $filter['kinds'] = $kinds;
                }
                $requests[] = $filter;
                continue;
            }

            foreach ($kinds as $kind) {
                $singleKindFilter = $filter;
                $singleKindFilter['kinds'] = [$kind];
                $requests[] = $singleKindFilter;
            }
        }

        return $requests !== [] ? $requests : [[]];
    }

    private function recordAuthRequiredIfNeeded(string $connectionUrl, string $message): void
    {
        $lower = strtolower($message);
        if (!str_contains($lower, 'auth')) {
            return;
        }

        $this->healthRecorder->setAuthRequired($connectionUrl);
        $this->healthRecorder->setAuthStatus($connectionUrl, 'failed');
    }

    /** @param array<string,string> $data */
    private function handlePublish(array $data, string $correlationId, int $defaultTimeout): void
    {
        $relayUrls = $this->decodeStringList($data['relays'] ?? '[]');
        $eventData = $this->decodeArray($data['event'] ?? '{}');
        $pubkey = $this->optionalString($data['pubkey'] ?? null);
        $timeout = max(1, (int) ($data['timeout'] ?? $defaultTimeout));

        $this->writePublishHeader($correlationId, count($relayUrls));

        foreach ($relayUrls as $relayUrl) {
            [$accepted, $message] = $this->publishToRelay($relayUrl, $eventData, $pubkey, $timeout);
            $this->writePartialPublishResponse($correlationId, $relayUrl, $accepted, $message, false);
        }

        $this->writePartialPublishResponse($correlationId, '', true, '', true);
    }

    /**
     * @param array<string,mixed> $eventData
     * @return array{0: bool, 1: string}
     */
    private function publishToRelay(string $relayUrl, array $eventData, ?string $pubkey, int $timeout): array
    {
        $connectionUrl = $this->relayUrlResolver->resolveToConnectionUrl($relayUrl);
        $connectionKey = null;

        try {
            $event = Event::fromArray($eventData);
            [$connectionKey, $client, $relay] = $this->getConnection(
                $connectionUrl,
                $pubkey,
                $pubkey === null && isset($this->persistentSharedConnectionKeys[$this->connectionKey($connectionUrl, null)]),
            );
            $startedAt = microtime(true);
            $sent = $client->publishEvent($relay, $event);
            $client->awaitPendingPublishes($relay, $timeout);
            $this->touchConnection($connectionKey);

            if ($sent) {
                $this->healthRecorder->recordSuccess($connectionUrl, (int) round((microtime(true) - $startedAt) * 1000));
            } else {
                $this->healthRecorder->recordFailure($connectionUrl);
            }

            if ($pubkey !== null && $pubkey !== '') {
                $this->activityRecorder->recordPublish($pubkey, $connectionUrl, $sent, $sent ? null : 'publish not accepted');
            }

            return [$sent, $sent ? '' : 'publish not accepted'];
        } catch (\Throwable $e) {
            $this->healthRecorder->recordFailure($connectionUrl);
            if ($connectionKey !== null) {
                $this->closeConnection($connectionKey);
            }
            if ($pubkey !== null && $pubkey !== '') {
                $this->activityRecorder->recordPublish($pubkey, $connectionUrl, false, $e->getMessage());
            }
            return [false, $e->getMessage()];
        }
    }

    /** @param array<string,string> $data */
    private function handleControl(array $data): void
    {
        $action = (string) ($data['action'] ?? '');
        if ($action === 'warm') {
            $pubkey = $this->optionalString($data['pubkey'] ?? null);
            if ($pubkey === null) {
                return;
            }

            $warmed = 0;
            foreach ($this->decodeStringList($data['relays'] ?? '[]') as $relayUrl) {
                $connectionUrl = $this->relayUrlResolver->resolveToConnectionUrl($relayUrl);
                try {
                    $this->getConnection($connectionUrl, $pubkey, persistent: false);
                    ++$warmed;
                } catch (\Throwable $e) {
                    $this->logger->warning('RelayGatewayBundle: warm connection failed', [
                        'relay' => $connectionUrl,
                        'pubkey' => substr($pubkey, 0, 8) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('RelayGatewayBundle: warm command processed', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'relay_count' => $warmed,
            ]);
            return;
        }

        if ($action === 'close') {
            $pubkey = $this->optionalString($data['pubkey'] ?? null);
            if ($pubkey !== null) {
                $closed = $this->closeUserConnections($pubkey);
                $this->logger->info('RelayGatewayBundle: close command processed', [
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'closed' => $closed,
                ]);
            }
        }
    }

    /** @return array{0: string, 1: NostrClientInterface, 2: RelayUrl} */
    private function getConnection(string $connectionUrl, ?string $pubkey, bool $persistent): array
    {
        $relay = RelayUrl::fromString($connectionUrl);
        if ($relay === null) {
            throw new \InvalidArgumentException('invalid relay URL');
        }

        $key = $this->connectionKey($connectionUrl, $pubkey);
        if (isset($this->connections[$key])) {
            $entry = $this->connections[$key];
            if (!$entry['client']->isConnected($entry['relay'])) {
                $entry['client']->connect($entry['relay'], $this->connectionConfig);
            }
            $entry['persistent'] = $entry['persistent'] || $persistent;
            $entry['last_used_at'] = time();
            $this->connections[$key] = $entry;

            return [$key, $entry['client'], $entry['relay']];
        }

        $this->enforceConnectionLimits($pubkey, $persistent);

        $client = $this->clientFactory->create();
        $client->setAuthHandler(new GatewayAuthChallengeHandler(
            $pubkey,
            $this->authChallengeSigner,
            $this->runtimeAuthTimeoutSeconds,
            $this->relayUrlResolver->resolveForAuth($connectionUrl),
        ));
        $client->connect($relay, $this->connectionConfig);

        $now = time();
        $this->connections[$key] = [
            'client' => $client,
            'relay' => $relay,
            'relay_url' => $connectionUrl,
            'pubkey' => $pubkey,
            'persistent' => $persistent,
            'created_at' => $now,
            'last_used_at' => $now,
        ];

        return [$key, $client, $relay];
    }

    private function enforceConnectionLimits(?string $pubkey, bool $persistent): void
    {
        if ($pubkey !== null) {
            if ($this->countUserConnections($pubkey) >= $this->maxConnectionsPerUser) {
                $this->closeIdlestUserConnection($pubkey);
            }
            if ($this->countAllUserConnections() >= $this->maxTotalUserConnections) {
                $this->closeIdlestUserConnection();
            }
            return;
        }

        if (!$persistent && $this->countOnDemandSharedConnections() >= $this->maxSharedConnections) {
            $this->closeIdlestOnDemandSharedConnection();
        }
    }

    private function performMaintenance(): void
    {
        $now = time();
        foreach (array_keys($this->connections) as $key) {
            if (!isset($this->connections[$key])) {
                continue;
            }

            $entry = $this->connections[$key];
            $idleSeconds = $now - $entry['last_used_at'];
            if ($entry['pubkey'] !== null && $idleSeconds > $this->userIdleTimeoutSeconds) {
                $this->closeConnection($key);
                continue;
            }

            if ($entry['pubkey'] === null && !$entry['persistent'] && $idleSeconds > $this->onDemandIdleTimeoutSeconds) {
                $this->closeConnection($key);
            }
        }
    }

    private function closeConnection(string $key): void
    {
        $entry = $this->connections[$key] ?? null;
        if ($entry === null) {
            return;
        }

        unset($this->connections[$key]);

        try {
            $entry['client']->disconnect($entry['relay']);
        } catch (\Throwable) {
        }

        try {
            $entry['client']->close();
        } catch (\Throwable) {
        }
    }

    private function closeAllConnections(): void
    {
        foreach (array_keys($this->connections) as $key) {
            $this->closeConnection($key);
        }
    }

    private function closeUserConnections(string $pubkey): int
    {
        $closed = 0;
        foreach (array_keys($this->connections) as $key) {
            if (($this->connections[$key]['pubkey'] ?? null) !== $pubkey) {
                continue;
            }
            $this->closeConnection($key);
            ++$closed;
        }

        return $closed;
    }

    private function closeIdlestUserConnection(?string $pubkey = null): void
    {
        $candidateKey = null;
        $candidateLastUsedAt = PHP_INT_MAX;

        foreach ($this->connections as $key => $entry) {
            if ($entry['pubkey'] === null) {
                continue;
            }
            if ($pubkey !== null && $entry['pubkey'] !== $pubkey) {
                continue;
            }
            if ($entry['last_used_at'] < $candidateLastUsedAt) {
                $candidateKey = $key;
                $candidateLastUsedAt = $entry['last_used_at'];
            }
        }

        if ($candidateKey === null) {
            throw new \RuntimeException('user connection limit reached and no idle user connection could be evicted');
        }

        $this->closeConnection($candidateKey);
    }

    private function closeIdlestOnDemandSharedConnection(): void
    {
        $candidateKey = null;
        $candidateLastUsedAt = PHP_INT_MAX;

        foreach ($this->connections as $key => $entry) {
            if ($entry['pubkey'] !== null || $entry['persistent']) {
                continue;
            }
            if ($entry['last_used_at'] < $candidateLastUsedAt) {
                $candidateKey = $key;
                $candidateLastUsedAt = $entry['last_used_at'];
            }
        }

        if ($candidateKey === null) {
            throw new \RuntimeException('shared connection limit reached and no idle on-demand connection could be evicted');
        }

        $this->closeConnection($candidateKey);
    }

    private function countUserConnections(string $pubkey): int
    {
        $count = 0;
        foreach ($this->connections as $entry) {
            if ($entry['pubkey'] === $pubkey) {
                ++$count;
            }
        }

        return $count;
    }

    private function countAllUserConnections(): int
    {
        $count = 0;
        foreach ($this->connections as $entry) {
            if ($entry['pubkey'] !== null) {
                ++$count;
            }
        }

        return $count;
    }

    private function countOnDemandSharedConnections(): int
    {
        $count = 0;
        foreach ($this->connections as $entry) {
            if ($entry['pubkey'] === null && !$entry['persistent']) {
                ++$count;
            }
        }

        return $count;
    }

    private function touchConnection(string $key): void
    {
        if (isset($this->connections[$key])) {
            $this->connections[$key]['last_used_at'] = time();
        }
    }

    private function connectionKey(string $relayUrl, ?string $pubkey): string
    {
        return hash('sha256', strtolower(rtrim($relayUrl, '/')) . "\0" . ($pubkey ?? ''));
    }

    /** @param list<array<string,mixed>> $events @param array<string,string> $errors */
    private function writeQueryResponse(string $correlationId, array $events, array $errors): void
    {
        $responseKey = self::RESPONSE_PREFIX . $correlationId;
        $this->redis->xAdd($responseKey, '*', [
            'events' => json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            'errors' => json_encode($errors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            'eose' => 'true',
        ]);
        $this->redis->expire($responseKey, $this->responseTtlSeconds);
    }

    private function writePublishHeader(string $correlationId, int $total): void
    {
        $responseKey = self::RESPONSE_PREFIX . $correlationId;
        $this->redis->xAdd($responseKey, '*', ['total' => (string) $total]);
        $this->redis->expire($responseKey, $this->responseTtlSeconds);
    }

    private function writePartialPublishResponse(string $correlationId, string $relayUrl, bool $accepted, string $message, bool $done): void
    {
        $responseKey = self::RESPONSE_PREFIX . $correlationId;
        $fields = ['done' => $done ? 'true' : 'false'];
        if ($relayUrl !== '') {
            $fields += [
                'relay' => $relayUrl,
                'ok' => $accepted ? 'true' : 'false',
                'message' => $message,
            ];
        }
        $this->redis->xAdd($responseKey, '*', $fields);
        $this->redis->expire($responseKey, $this->responseTtlSeconds);
    }

    /** @param array<string,string> $data @return list<array<string,mixed>> */
    private function decodeFilters(array $data): array
    {
        if (isset($data['filters'])) {
            $filters = $this->decodeArray($data['filters']);
            if (isset($filters[0]) && is_array($filters[0])) {
                return array_values(array_filter($filters, 'is_array'));
            }
        }

        return [$this->decodeArray($data['filter'] ?? '{}')];
    }

    /** @return array<string|int,mixed> */
    private function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return string[] */
    private function decodeStringList(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn(string $value): bool => $value !== ''));
    }

    private function optionalString(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function readCursor(string $name): ?string
    {
        try {
            $cursor = $this->redis->get('relay_gateway:cursor:' . $name);
            return is_string($cursor) && $cursor !== '' ? $cursor : null;
        } catch (\RedisException) {
            return null;
        }
    }

    private function writeCursor(string $name, string $cursor): void
    {
        try {
            $this->redis->set('relay_gateway:cursor:' . $name, $cursor, ['ex' => $this->heartbeatTtlSeconds]);
        } catch (\RedisException) {
        }
    }

    private function writeHeartbeat(bool $force = false): void
    {
        $now = time();
        if (!$force && $now - $this->lastHeartbeatAt < $this->heartbeatIntervalSeconds) {
            return;
        }

        try {
            $this->redis->set('relay_gateway:heartbeat', (string) $now, ['ex' => $this->heartbeatTtlSeconds]);
            $this->redis->set('relay_gateway:cursor:requests', $this->lastRequestId, ['ex' => $this->heartbeatTtlSeconds]);
            $this->redis->set('relay_gateway:cursor:control', $this->lastControlId, ['ex' => $this->heartbeatTtlSeconds]);
            $this->lastHeartbeatAt = $now;
        } catch (\RedisException $e) {
            $this->logger->warning('RelayGatewayBundle: failed to write heartbeat', ['error' => $e->getMessage()]);
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->shouldStop = true; });
        pcntl_signal(SIGINT, function (): void { $this->shouldStop = true; });
    }
}

