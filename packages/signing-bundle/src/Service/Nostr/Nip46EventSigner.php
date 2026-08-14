<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use DecentNewsroom\SigningBundle\Contract\Nip46AuthEventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\Nip46EventSignerInterface;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;
use Innis\Nostr\Core\Domain\Entity\Event as CoreEvent;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\Service\EventValidationServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;
use swentel\nostr\Encryption\Nip04;
use swentel\nostr\Event\Event as SwentelEvent;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Symfony\Component\Uid\Uuid;

use function Amp\delay;

final class Nip46EventSigner implements Nip46EventSignerInterface, Nip46AuthEventSignerInterface
{
    public const DEFAULT_TIMEOUT = 15;

    private Key $keyUtil;

    public function __construct(
        private readonly NostrClientFactoryInterface $clientFactory,
        private readonly EventValidationServiceInterface $eventValidation,
        private readonly RelayAuthEventFactory $authEventFactory,
        private readonly LoggerInterface $logger,
        private readonly int $defaultTimeoutSeconds = self::DEFAULT_TIMEOUT,
    ) {
        $this->keyUtil = new Key();
    }

    /**
     * @param array<string, mixed> $unsignedEvent
     * @return array<string, mixed>|null
     */
    public function signEvent(
        string $subjectPubkeyHex,
        array $unsignedEvent,
        RemoteSignerSession $session,
        ?int $timeoutSeconds = null,
    ): ?array {
        $timeoutSeconds = max(1, $timeoutSeconds ?? $this->defaultTimeoutSeconds);
        $unsignedEvent = $this->normalizeUnsignedEvent($subjectPubkeyHex, $unsignedEvent);
        if ($unsignedEvent === null) {
            return null;
        }

        if (!$this->sessionLooksComplete($session)) {
            $this->logger->warning('NIP-46 session is incomplete');
            return null;
        }

        if ($session->userPubkeyHex() !== null && $session->userPubkeyHex() !== $subjectPubkeyHex) {
            $this->logger->warning('NIP-46 session subject does not match requested signer', [
                'session_subject' => substr($session->userPubkeyHex(), 0, 8).'...',
                'requested_subject' => substr($subjectPubkeyHex, 0, 8).'...',
            ]);

            return null;
        }

        try {
            $clientPubkeyHex = $this->keyUtil->getPublicKey($session->clientPrivkeyHex());
            $requestId = Uuid::v4()->toRfc4122();
            $requestEvent = $this->buildRequestEvent($requestId, $unsignedEvent, $session);
            $coreRequestEvent = CoreEvent::fromArray($requestEvent->toArray());
            $handler = new Nip46ResponseHandler(
                $requestId,
                $session->clientPrivkeyHex(),
                $session->remoteSignerPubkeyHex(),
                $clientPubkeyHex,
                $this->logger,
            );
        } catch (\Throwable $e) {
            $this->logger->error('NIP-46 request event could not be built', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $client = $this->clientFactory->create();
        $config = $this->clientFactory->createDefaultConnectionConfig();
        $subscriptions = [];
        $published = 0;

        try {
            $filter = Filter::fromArray([
                'kinds' => [24133],
                'authors' => [$session->remoteSignerPubkeyHex()],
                '#p' => [$clientPubkeyHex],
                'since' => time() - 5,
                'limit' => 5,
            ]);

            foreach ($session->relayUrls() as $relayUrl) {
                try {
                    $relay = RelayUrl::fromString($relayUrl);
                    $client->connect($relay, $config);
                    $subscriptionId = $client->subscribe(
                        $relay,
                        $filter,
                        $handler,
                        SubscriptionId::fromString('nip46-'.bin2hex(random_bytes(6))),
                    );
                    $subscriptions[] = [$relay, $subscriptionId];

                    if ($client->publishEvent($relay, $coreRequestEvent)) {
                        ++$published;
                        $client->awaitPendingPublishes($relay, min(1.0, (float) $timeoutSeconds));
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('NIP-46 bunker relay request failed', [
                        'relay' => $relayUrl,
                        'request_id' => $requestId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($published === 0) {
                $this->logger->warning('NIP-46 request was not published to any bunker relay', [
                    'request_id' => $requestId,
                    'relay_count' => count($session->relayUrls()),
                ]);

                return null;
            }

            $deadline = microtime(true) + $timeoutSeconds;
            while (!$handler->isDone() && microtime(true) < $deadline) {
                delay(0.05);
            }

            if ($handler->signedEvent() === null) {
                $this->logger->warning('NIP-46 signer did not return a signed event', [
                    'request_id' => $requestId,
                    'error' => $handler->error(),
                    'timeout' => $timeoutSeconds,
                ]);

                return null;
            }

            return $this->validateSignedEvent($subjectPubkeyHex, $unsignedEvent, $handler->signedEvent());
        } finally {
            foreach ($subscriptions as [$relay, $subscriptionId]) {
                try {
                    $client->unsubscribe($relay, $subscriptionId);
                } catch (\Throwable) {
                }
            }

            try {
                $client->close();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: list<string>} $session
     * @return array<string, mixed>|null
     */
    public function signAuthEvent(
        string $userPubkeyHex,
        string $relayUrl,
        string $challenge,
        array $session,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ): ?array {
        return $this->signEvent(
            $userPubkeyHex,
            $this->authEventFactory->create($userPubkeyHex, $relayUrl, $challenge),
            RemoteSignerSession::fromLegacyArray($session, $userPubkeyHex),
            $timeoutSeconds,
        );
    }

    /**
     * @param array<string, mixed> $unsignedEvent
     * @return array<string, mixed>|null
     */
    private function normalizeUnsignedEvent(string $subjectPubkeyHex, array $unsignedEvent): ?array
    {
        if (!isset($unsignedEvent['kind']) || !is_numeric($unsignedEvent['kind'])) {
            $this->logger->warning('NIP-46 unsigned event is missing kind');
            return null;
        }

        $eventPubkey = (string) ($unsignedEvent['pubkey'] ?? $subjectPubkeyHex);
        if ($eventPubkey !== $subjectPubkeyHex) {
            $this->logger->warning('NIP-46 unsigned event pubkey does not match requested signer', [
                'event_pubkey' => substr($eventPubkey, 0, 8).'...',
                'requested_subject' => substr($subjectPubkeyHex, 0, 8).'...',
            ]);

            return null;
        }

        $tags = $unsignedEvent['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }

        return [
            'kind' => (int) $unsignedEvent['kind'],
            'pubkey' => $subjectPubkeyHex,
            'created_at' => isset($unsignedEvent['created_at']) && is_numeric($unsignedEvent['created_at']) ? (int) $unsignedEvent['created_at'] : time(),
            'tags' => $this->normalizeTags($tags),
            'content' => $this->normalizeContent($unsignedEvent['content'] ?? ''),
        ];
    }

    /**
     * @param array<int, mixed> $tags
     * @return list<list<string>>
     */
    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            $values = [];
            foreach ($tag as $value) {
                if (is_scalar($value)) {
                    $values[] = (string) $value;
                }
            }

            if ($values !== []) {
                $normalized[] = $values;
            }
        }

        return $normalized;
    }

    private function normalizeContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $encoded = json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);

        return $encoded === false ? '' : $encoded;
    }

    private function sessionLooksComplete(RemoteSignerSession $session): bool
    {
        return $session->clientPrivkeyHex() !== ''
            && $session->remoteSignerPubkeyHex() !== ''
            && $session->relayUrls() !== [];
    }

    /**
     * @param array<string, mixed> $unsignedEvent
     */
    private function buildRequestEvent(string $requestId, array $unsignedEvent, RemoteSignerSession $session): SwentelEvent
    {
        $unsignedEventJson = json_encode($unsignedEvent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        $rpcRequestJson = json_encode([
            'id' => $requestId,
            'method' => 'sign_event',
            'params' => [$unsignedEventJson],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);

        $requestEvent = new SwentelEvent();
        $requestEvent->setKind(24133);
        $requestEvent->setContent(Nip04::encrypt($rpcRequestJson, $session->clientPrivkeyHex(), $session->remoteSignerPubkeyHex()));
        $requestEvent->setTags([['p', $session->remoteSignerPubkeyHex()]]);

        (new Sign())->signEvent($requestEvent, $session->clientPrivkeyHex());

        return $requestEvent;
    }

    /**
     * @param array<string, mixed> $unsignedEvent
     * @param array<string, mixed> $signedEvent
     * @return array<string, mixed>|null
     */
    private function validateSignedEvent(string $subjectPubkeyHex, array $unsignedEvent, array $signedEvent): ?array
    {
        $signedEvent = $this->normalizeSignedEvent($signedEvent);
        if ($signedEvent === null) {
            return null;
        }

        foreach (['pubkey', 'kind', 'created_at', 'tags', 'content'] as $field) {
            if ($signedEvent[$field] !== $unsignedEvent[$field]) {
                $this->logger->warning('NIP-46 signed event did not match requested event', [
                    'field' => $field,
                    'subject' => substr($subjectPubkeyHex, 0, 8).'...',
                ]);

                return null;
            }
        }

        if ((string) ($signedEvent['pubkey'] ?? '') !== $subjectPubkeyHex) {
            $this->logger->warning('NIP-46 signed event pubkey does not match requested signer');
            return null;
        }

        if (empty($signedEvent['id']) || empty($signedEvent['sig'])) {
            $this->logger->warning('NIP-46 signed event is missing id or signature');
            return null;
        }

        try {
            $event = CoreEvent::fromArray($signedEvent);
        } catch (\Throwable $e) {
            $this->logger->warning('NIP-46 signed event could not be parsed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$this->eventValidation->isEventValid($event)) {
            $this->logger->warning('NIP-46 signed event failed signature validation', [
                'event_id' => substr((string) ($signedEvent['id'] ?? ''), 0, 16),
            ]);

            return null;
        }

        return $signedEvent;
    }

    /**
     * @param array<string, mixed> $signedEvent
     * @return array<string, mixed>|null
     */
    private function normalizeSignedEvent(array $signedEvent): ?array
    {
        foreach (['pubkey', 'created_at', 'kind', 'tags', 'content', 'id', 'sig'] as $field) {
            if (!array_key_exists($field, $signedEvent)) {
                return null;
            }
        }

        if (!is_array($signedEvent['tags'])) {
            return null;
        }

        return [
            'id' => (string) $signedEvent['id'],
            'pubkey' => (string) $signedEvent['pubkey'],
            'created_at' => (int) $signedEvent['created_at'],
            'kind' => (int) $signedEvent['kind'],
            'tags' => $this->normalizeTags($signedEvent['tags']),
            'content' => $this->normalizeContent($signedEvent['content']),
            'sig' => (string) $signedEvent['sig'],
        ];
    }
}
