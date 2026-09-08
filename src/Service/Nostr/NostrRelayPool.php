<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Util\RelayUrlNormalizer;
use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use DecentNewsroom\SigningBundle\Contract\CurrentSubjectPubkeyResolverInterface;
use DecentNewsroom\SigningBundle\Contract\RelayAuthSignerInterface;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;

use function Amp\delay;

/**
 * Application relay pool.
 *
 * Relay URLs, filters and events are converted at this boundary. No Swentel
 * transport or response object is exposed by the pool.
 */
class NostrRelayPool implements RelayPoolInterface
{
    /** @var array<string, RelayEndpoint> */
    private array $relays = [];

    /** @var array<string, int> */
    private array $connectionAttempts = [];

    /** @var array<string, int> */
    private array $lastConnected = [];

    /** @var string[] */
    private array $defaultRelays;

    private const CONNECTION_TIMEOUT = 15;

    /**
     * @param string[] $defaultRelays
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RelayRegistry $relayRegistry,
        private readonly RelayHealthStore $healthStore,
        private readonly string $nostrDefaultRelay,
        private readonly bool $gatewayEnabled = false,
        private readonly ?RelayGatewayClient $gatewayClient = null,
        array $defaultRelays = [],
        private readonly ?RelayAuthSignerInterface $relayAuthSigner = null,
        private readonly ?CurrentSubjectPubkeyResolverInterface $currentSubjectPubkeyResolver = null,
        private readonly ?NostrClientFactoryInterface $nostrClientFactory = null,
    ) {
        $this->defaultRelays = $defaultRelays !== []
            ? $this->prioritiseLocal($defaultRelays)
            : $this->relayRegistry->getDefaultRelays();
    }

    public function getRelay(string $relayUrl): RelayEndpoint
    {
        $relayUrl = $this->normalizeRelayUrl($relayUrl);
        return $this->relays[$relayUrl] ??= $this->rememberRelay(new RelayEndpoint($relayUrl));
    }

    public function getRelays(array $relayUrls): array
    {
        $urls = array_values(array_unique(array_map(fn (string $url): string => $this->normalizeRelayUrl($url), $relayUrls)));
        $local = $this->nostrDefaultRelay !== ''
            ? $this->normalizeRelayUrl($this->nostrDefaultRelay)
            : null;
        $ordered = [];
        if ($local !== null) {
            $ordered[] = $local;
        }
        $external = array_values(array_filter($urls, fn (string $url): bool => $url !== $local && !$this->healthStore->isMuted($url)));
        foreach ($this->sortByHealthScore($external) as $url) {
            $ordered[] = $url;
        }

        return array_map(fn (string $url): RelayEndpoint => $this->getRelay($url), array_values(array_unique($ordered)));
    }

    /**
     * Compatibility adapter for callers still constructing a request payload.
     *
     * @return array<string, array<int, object>>
     */
    public function sendToRelays(
        array $relayUrls,
        callable $messageBuilder,
        ?int $timeout = null,
        ?string $subscriptionId = null,
        ?string $pubkey = null,
    ): array {
        $message = $messageBuilder();
        $payload = is_string($message) ? $message : (method_exists($message, 'generate') ? $message->generate() : '');
        $filters = $this->extractFiltersFromPayload($payload);
        $request = new RelayQueryRequest(new RelaySet(
            array_map(fn (string $url): RelayEndpoint => new RelayEndpoint($url), $relayUrls),
        ), $filters);
        $request->setTimeout($timeout ?? self::CONNECTION_TIMEOUT)->requestedBy(
            $pubkey ?? $this->currentSubjectPubkeyResolver?->resolveCurrentSubjectPubkeyHex()
        );

        $typed = $this->executeRequest($request);
        $responses = [];
        foreach ($typed as $url => $result) {
            $responses[$url] = array_map(
                static fn (Event $event): object => (object) ['type' => 'EVENT', 'event' => (object) $event->toArray()],
                $result->events,
            );
        }

        return $responses;
    }

    /** @return array<string, RelayQueryResult> */
    public function executeRequest(RelayQueryRequest $request): array
    {
        $relayUrls = $this->filterRelayUrls($request->getRelaySet()->getUrls());
        if ($relayUrls === []) {
            return [];
        }

        if ($this->isGatewayEnabled()) {
            [$localUrls, $externalUrls] = $this->partitionRelays($relayUrls);
            $results = [];
            if ($localUrls !== []) {
                $results += $this->queryDirect($localUrls, $request);
            }
            if ($externalUrls !== []) {
                $results += $this->queryGateway($externalUrls, $request);
            }
            return $results;
        }

        return $this->queryDirect($relayUrls, $request);
    }

    /**
     * @param string[] $relayUrls
     * @return array<string, RelayQueryResult>
     */
    private function queryGateway(array $relayUrls, RelayQueryRequest $request): array
    {
        if ($this->gatewayClient === null) {
            return [];
        }

        try {
            $gatewayResult = $this->gatewayClient->query(
                $relayUrls,
                $request->getFilters(),
                $request->getRequestedBy(),
                $request->getGatewayTimeout(),
            );
            $errors = $gatewayResult['errors'];
            $events = [];
            foreach ($gatewayResult['events'] as $rawEvent) {
                if (is_array($rawEvent)) {
                    try {
                        $events[] = Event::fromArray($rawEvent);
                    } catch (\Throwable $e) {
                        $this->logger->warning('Ignoring malformed gateway event', ['error' => $e->getMessage()]);
                    }
                }
            }

            $results = [];
            foreach ($relayUrls as $url) {
                $error = $errors[$url] ?? null;
                $results[$url] = new RelayQueryResult($url, $events, $error === null, is_string($error) ? $error : null);
                if ($error === null) {
                    $this->healthStore->recordSuccess($url, 0);
                } else {
                    $this->healthStore->recordFailure($url);
                }
            }

            return $results;
        } catch (\Throwable $e) {
            $this->logger->warning('Gateway query failed', ['error' => $e->getMessage()]);
            $results = [];
            foreach ($relayUrls as $url) {
                $results[$url] = new RelayQueryResult($url, [], false, $e->getMessage());
            }
            return $results;
        }
    }

    /**
     * @param string[] $relayUrls
     * @return array<string, RelayQueryResult>
     */
    private function queryDirect(array $relayUrls, RelayQueryRequest $request): array
    {
        if ($this->nostrClientFactory === null) {
            $message = 'Nostr client factory is not configured';
            $this->logger->error($message);
            $results = [];
            foreach ($relayUrls as $url) {
                $results[$url] = new RelayQueryResult($url, [], false, $message);
            }
            return $results;
        }

        $results = [];
        foreach ($this->getRelays($relayUrls) as $endpoint) {
            $url = $endpoint->getUrl();
            $started = microtime(true);
            $client = $this->nostrClientFactory->create();
            /** @var Event[] $events */
            $events = [];
            $eose = false;
            $closed = false;
            $closeMessage = null;
            $lastMessage = microtime(true);
            $subscriptionId = SubscriptionId::fromString($request->getSubscriptionId());
            $relay = RelayUrl::fromString($url);
            if ($relay === null) {
                $results[$url] = new RelayQueryResult($url, [], false, 'Invalid relay URL');
                continue;
            }

            try {
                $client->setAuthHandler($this->authHandler($url, $request->getRequestedBy(), $client, $relay, $subscriptionId));
                $client->connect($relay, $this->nostrClientFactory->createDefaultConnectionConfig());
                $client->subscribeMultiple(
                    $relay,
                    array_map(static fn (array $filterData): Filter => Filter::fromArray($filterData), $request->getFilters()),
                    $this->eventHandler($events, $eose, $closed, $closeMessage, $lastMessage, $request->getStopOnEventId()),
                    $subscriptionId,
                );

                while (!$eose && !$closed && microtime(true) - $lastMessage < $request->getTimeout()) {
                    $this->ensureSubscriptionAlive($client, $relay, $subscriptionId);
                    delay(0.1);
                }
                $completed = $eose && !$closed;
                $results[$url] = new RelayQueryResult(
                    $url,
                    $events,
                    $completed,
                    $completed ? null : ($closeMessage ?? 'Idle timeout before EOSE'),
                    (int) ((microtime(true) - $started) * 1000),
                );
                if ($completed) {
                    $this->healthStore->recordSuccess($url, (int) ((microtime(true) - $started) * 1000));
                    $this->connectionAttempts[$url] = 0;
                    $this->lastConnected[$url] = time();
                } else {
                    $this->healthStore->recordFailure($url);
                }
            } catch (\Throwable $e) {
                $this->connectionAttempts[$url] = ($this->connectionAttempts[$url] ?? 0) + 1;
                $this->healthStore->recordFailure($url);
                $results[$url] = new RelayQueryResult(
                    $url,
                    $events,
                    false,
                    $e->getMessage(),
                    (int) ((microtime(true) - $started) * 1000),
                );
            } finally {
                try {
                    $client->unsubscribe($relay, $subscriptionId);
                } catch (\Throwable) {
                }
                try {
                    $client->close();
                } catch (\Throwable) {
                }
            }
        }

        return $results;
    }

    private function authHandler(
        string $relayUrl,
        ?string $pubkey,
        object $client,
        RelayUrl $relay,
        SubscriptionId $subscriptionId,
    ): AuthChallengeHandlerInterface {
        return new class($this->relayAuthSigner, $pubkey, $relayUrl, $this->logger) implements AuthChallengeHandlerInterface {
            public function __construct(
                private readonly ?RelayAuthSignerInterface $signer,
                private readonly ?string $pubkey,
                private readonly string $relayUrl,
                private readonly LoggerInterface $logger,
            ) {
            }

            public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
            {
                if ($this->signer === null || $this->pubkey === null || !$this->signer->supportsRelayAuth($this->pubkey)) {
                    $this->logger->info('Dropping anonymous or unsupported AUTH-gated relay request', ['relay' => $this->relayUrl]);
                    return null;
                }
                $signed = $this->signer->signRelayAuth($this->pubkey, $this->relayUrl, $challenge);
                return $signed === null ? null : Event::fromArray($signed);
            }
        };
    }

    /** @param list<Event> $events */
    private function eventHandler(
        array &$events,
        bool &$eose,
        bool &$closed,
        ?string &$closeMessage,
        float &$lastMessage,
        ?string $stopOnEventId,
    ): EventHandlerInterface
    {
        return new class($events, $eose, $closed, $closeMessage, $lastMessage, $stopOnEventId) implements EventHandlerInterface {
            /** @param list<Event> $events */
            public function __construct(
                private array &$events,
                private bool &$eose,
                private bool &$closed,
                private ?string &$closeMessage,
                private float &$lastMessage,
                private readonly ?string $stopOnEventId,
            ) {
            }

            public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
            {
                $this->lastMessage = microtime(true);
                $this->events[] = $event;
                if ($this->stopOnEventId !== null && $event->getId()->toHex() === $this->stopOnEventId) {
                    $this->eose = true;
                }
            }

            public function handleEose(SubscriptionId $subscriptionId): void
            {
                $this->lastMessage = microtime(true);
                $this->eose = true;
            }

            public function handleClosed(SubscriptionId $subscriptionId, string $message): void
            {
                $this->lastMessage = microtime(true);
                $this->closed = true;
                $this->closeMessage = $message !== '' ? $message : 'Relay closed the subscription';
            }

            public function handleNotice(RelayUrl $relayUrl, string $message): void
            {
                $this->lastMessage = microtime(true);
            }
        };
    }

    /** @param string[] $relayUrls @return array<string, array<string, mixed>> */
    public function publish(object $event, array $relayUrls, ?string $pubkey = null, int $timeout = 30): array
    {
        if ($this->nostrClientFactory === null) {
            return [];
        }

        $event = $this->toCoreEvent($event);
        /** @var array<string, array<string, mixed>> $results */
        $results = [];
        foreach ($this->getRelays($relayUrls) as $endpoint) {
            $url = $endpoint->getUrl();
            $started = microtime(true);
            $client = $this->nostrClientFactory->create();
            try {
                $relay = RelayUrl::fromString($url);
                if ($relay === null) {
                    throw new \InvalidArgumentException('Invalid relay URL');
                }
                $client->setAuthHandler($this->authHandler($url, $pubkey, $client, $relay, SubscriptionId::fromString('publish-'.bin2hex(random_bytes(4)))));
                $client->connect($relay, $this->nostrClientFactory->createDefaultConnectionConfig());
                $ok = $client->publishEvent($relay, $event);
                if (!$ok) {
                    throw new \RuntimeException('Relay client rejected the publish request');
                }
                $deadline = $started + max(1, $timeout);
                $remaining = max(0.001, $deadline - microtime(true));
                $client->awaitPendingPublishes($relay, $remaining);
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting for relay OK response');
                }
                $elapsed = (int) ((microtime(true) - $started) * 1000);
                $results[$url] = ['ok' => true, 'latency_ms' => $elapsed];
                $this->healthStore->recordSuccess($url, $elapsed);
            } catch (\Throwable $e) {
                $results[$url] = ['ok' => false, 'message' => $e->getMessage()];
                $this->healthStore->recordFailure($url);
            } finally {
                try {
                    $client->close();
                } catch (\Throwable) {
                }
            }
        }

        return $results;
    }

    private function toCoreEvent(object $event): Event
    {
        if ($event instanceof Event) {
            return $event;
        }
        if (method_exists($event, 'toArray')) {
            return Event::fromArray($event->toArray());
        }
        return Event::fromArray(get_object_vars($event));
    }

    public function isGatewayEnabled(): bool
    {
        return $this->gatewayEnabled && $this->gatewayClient !== null;
    }

    public function getGatewayClient(): ?RelayGatewayClient
    {
        return $this->gatewayClient;
    }

    public function closeRelay(string $relayUrl): void
    {
        $url = $this->normalizeRelayUrl($relayUrl);
        unset($this->relays[$url], $this->connectionAttempts[$url], $this->lastConnected[$url]);
    }

    public function closeAll(): void
    {
        $this->relays = [];
        $this->connectionAttempts = [];
        $this->lastConnected = [];
    }

    /** @return array{active_connections: int, relays: array<string, array{url: string, attempts: int, last_connected: int|null, age: int}>} */
    public function getStats(): array
    {
        $relayStats = [];
        foreach (array_keys($this->relays) as $url) {
            $relayStats[$url] = [
                'url' => $url,
                'attempts' => $this->connectionAttempts[$url] ?? 0,
                'last_connected' => $this->lastConnected[$url] ?? null,
                'age' => time() - ($this->lastConnected[$url] ?? time()),
            ];
        }

        return [
            'active_connections' => count($this->relays),
            'relays' => $relayStats,
        ];
    }

    public function cleanupStaleConnections(int $maxAge = 300): int
    {
        $now = time();
        $cleaned = 0;
        foreach (array_keys($this->lastConnected) as $url) {
            if ($now - ($this->lastConnected[$url] ?? 0) > $maxAge) {
                $this->closeRelay($url);
                $cleaned++;
            }
        }
        return $cleaned;
    }

    /** @return string[] */
    public function getDefaultRelays(): array
    {
        return $this->defaultRelays;
    }

    /** @param int[] $kinds */
    public function subscribeLocal(
        array $kinds,
        callable $onEvent,
        string $workerName = 'generic',
        ?int $since = null,
        ?string $relayUrlOverride = null,
    ): void {
        $relayUrl = $relayUrlOverride ?? $this->nostrDefaultRelay;
        if ($relayUrl === '') {
            throw new \RuntimeException('Local relay not configured. Set NOSTR_DEFAULT_RELAY environment variable.');
        }
        $this->runSubscription($relayUrl, ['kinds' => $kinds] + ($since !== null ? ['since' => $since] : []), $onEvent, $workerName);
    }

    public function subscribeLocalArticles(callable $onArticleEvent, ?int $since = null): void
    {
        $this->subscribeLocal([30023], $onArticleEvent, 'articles', $since);
    }

    public function subscribeLocalMedia(callable $onMediaEvent, ?int $since = null): void
    {
        $this->subscribeLocal([20, 21, 22, 34235, 34236], $onMediaEvent, 'media', $since);
    }

    public function subscribeLocalGenericEvents(array $kinds, callable $onEvent, ?int $since = null): void
    {
        $this->subscribeLocal($kinds, $onEvent, 'generic', $since);
    }

    /** @param array<string, mixed> $filterData */
    private function runSubscription(string $relayUrl, array $filterData, callable $onEvent, string $workerName): void
    {
        if ($this->nostrClientFactory === null) {
            throw new \RuntimeException('Nostr client factory is not configured');
        }
        $url = $this->normalizeRelayUrl($relayUrl);
        $relay = RelayUrl::fromString($url);
        if ($relay === null) {
            throw new \InvalidArgumentException('Invalid relay URL');
        }
        $client = $this->nostrClientFactory->create();
        $subscriptionId = SubscriptionId::fromString($workerName.'-'.bin2hex(random_bytes(6)));
        try {
            $client->setAuthHandler($this->authHandler($url, null, $client, $relay, $subscriptionId));
            $client->connect($relay, $this->nostrClientFactory->createDefaultConnectionConfig());
            $handler = new class($onEvent, $url, $this->healthStore) implements EventHandlerInterface {
                public function __construct(
                    private readonly \Closure $onEvent,
                    private readonly string $relayUrl,
                    private readonly RelayHealthStore $healthStore,
                ) {
                }
                public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
                {
                    $this->healthStore->recordEventReceived($this->relayUrl);
                    ($this->onEvent)((object) $event->toArray(), $this->relayUrl);
                }
                public function handleEose(SubscriptionId $subscriptionId): void {}
                public function handleClosed(SubscriptionId $subscriptionId, string $message): void { throw new \RuntimeException($message); }
                public function handleNotice(RelayUrl $relayUrl, string $message): void {}
            };
            $client->subscribe($relay, Filter::fromArray($filterData), $handler, $subscriptionId);
            for (;;) {
                $this->ensureSubscriptionAlive($client, $relay, $subscriptionId);
                delay(1);
            }
        } finally {
            try { $client->unsubscribe($relay, $subscriptionId); } catch (\Throwable) {}
            try { $client->close(); } catch (\Throwable) {}
        }
    }

    private function ensureSubscriptionAlive(
        NostrClientInterface $client,
        RelayUrl $relay,
        SubscriptionId $subscriptionId,
    ): void {
        if (!$client->isConnected($relay)) {
            throw new \RuntimeException('Relay connection closed while subscription was active');
        }

        $connection = $client->getConnection($relay);
        if ($connection === null || !$connection->hasSubscription($subscriptionId)) {
            throw new \RuntimeException('Relay subscription was removed while worker was active');
        }
    }

    public function fetchLocalUntilEose(
        /** @var int[] $kinds */
        array $kinds,
        callable $onEvent,
        ?int $since = null,
        ?int $until = null,
        ?int $limit = null,
        int $idleTimeoutSeconds = 30,
    ): int {
        if ($this->nostrDefaultRelay === '') {
            throw new \RuntimeException('Local relay not configured. Set NOSTR_DEFAULT_RELAY environment variable.');
        }
        $request = new RelayQueryRequest(
            new RelaySet([new RelayEndpoint($this->normalizeRelayUrl($this->nostrDefaultRelay))]),
            [array_filter([
                'kinds' => $kinds,
                'since' => $since,
                'until' => $until,
                'limit' => $limit,
            ], static fn (mixed $value): bool => $value !== null)],
        );
        $request->setTimeout($idleTimeoutSeconds);
        $results = $this->executeRequest($request);
        $count = 0;
        foreach ($results as $url => $result) {
            if (!$result->eose) {
                throw new \RuntimeException(sprintf(
                    'Local relay query did not reach EOSE: %s',
                    $result->error ?? 'connection closed or idle timeout',
                ));
            }
            foreach ($result->events as $event) {
                $count++;
                $onEvent((object) $event->toArray(), $url);
            }
        }
        return $count;
    }

    private function rememberRelay(RelayEndpoint $endpoint): RelayEndpoint
    {
        $url = $endpoint->getUrl();
        $this->connectionAttempts[$url] ??= 0;
        $this->lastConnected[$url] ??= time();
        return $endpoint;
    }

    private function normalizeRelayUrl(string $url): string
    {
        return RelayUrlNormalizer::normalize($url);
    }

    /** @param string[] $urls @return string[] */
    private function prioritiseLocal(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls, 'is_string')));
        if ($this->nostrDefaultRelay !== '') {
            $local = $this->normalizeRelayUrl($this->nostrDefaultRelay);
            $urls = array_values(array_filter($urls, fn (string $url): bool => $this->normalizeRelayUrl($url) !== $local));
            array_unshift($urls, $local);
        }
        return $urls;
    }

    /** @param string[] $urls @return string[] */
    private function filterRelayUrls(array $urls): array
    {
        $local = $this->nostrDefaultRelay !== '' ? $this->normalizeRelayUrl($this->nostrDefaultRelay) : null;
        return array_values(array_filter(
            array_unique(array_map(fn (string $url): string => $this->normalizeRelayUrl($url), $urls)),
            fn (string $url): bool => $url === $local || !$this->healthStore->isMuted($url),
        ));
    }

    /** @return array{0: string[], 1: string[]} */
    /** @param string[] $relayUrls @return array{0: string[], 1: string[]} */
    private function partitionRelays(array $relayUrls): array
    {
        $local = $this->nostrDefaultRelay !== '' ? $this->normalizeRelayUrl($this->nostrDefaultRelay) : null;
        $project = $this->relayRegistry->getProjectRelay();
        $project = $project !== null ? $this->normalizeRelayUrl($project) : null;
        $localUrls = [];
        $externalUrls = [];
        foreach ($relayUrls as $url) {
            $normalized = $this->normalizeRelayUrl($url);
            if ($normalized === $local) {
                $localUrls[] = $normalized;
            } elseif ($project !== null && $normalized === $project) {
                $localUrls[] = $this->relayRegistry->resolveToLocalUrl($url);
            } else {
                $externalUrls[] = $normalized;
            }
        }
        return [array_values(array_unique($localUrls)), array_values(array_unique($externalUrls))];
    }

    /** @param string[] $urls @return string[] */
    private function sortByHealthScore(array $urls): array
    {
        usort($urls, fn (string $a, string $b): int => $this->healthStore->getHealthScore($b) <=> $this->healthStore->getHealthScore($a));
        return $urls;
    }

    /** @return array<int, array<string, mixed>> */
    private function extractFiltersFromPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || ($decoded[0] ?? null) !== 'REQ') {
            return [];
        }
        return array_values(array_filter(array_slice($decoded, 2), 'is_array'));
    }
}
