<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use Psr\Log\LoggerInterface;
use swentel\nostr\Encryption\Nip04;

final class Nip46ResponseHandler implements EventHandlerInterface
{
    /** @var array<string, mixed>|null */
    private ?array $signedEvent = null;
    private ?string $error = null;

    public function __construct(
        private readonly string $requestId,
        private readonly string $clientPrivkeyHex,
        private readonly string $remoteSignerPubkeyHex,
        private readonly string $clientPubkeyHex,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
    {
        $data = $event->toArray();
        if ((int) ($data['kind'] ?? 0) !== 24133) {
            return;
        }

        if ((string) ($data['pubkey'] ?? '') !== $this->remoteSignerPubkeyHex) {
            return;
        }

        try {
            $decrypted = Nip04::decrypt((string) ($data['content'] ?? ''), $this->clientPrivkeyHex, $this->remoteSignerPubkeyHex);
            $response = json_decode($decrypted, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->debug('NIP-46 response could not be decrypted', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (!is_array($response) || (string) ($response['id'] ?? '') !== $this->requestId) {
            return;
        }

        if (!empty($response['error'])) {
            $this->error = (string) $response['error'];
            $this->logger->warning('NIP-46 remote signer returned an error', [
                'request_id' => $this->requestId,
                'error' => $this->error,
            ]);

            return;
        }

        $result = $response['result'] ?? null;
        if (is_string($result)) {
            try {
                $result = json_decode($result, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->error = 'NIP-46 response result is not valid JSON';
                $this->logger->warning('NIP-46 signed event JSON could not be decoded', [
                    'request_id' => $this->requestId,
                    'error' => $e->getMessage(),
                ]);

                return;
            }
        }

        if (!is_array($result)) {
            $this->error = 'NIP-46 response did not contain a signed event';
            return;
        }

        $this->signedEvent = $result;
    }

    public function handleEose(SubscriptionId $subscriptionId): void
    {
    }

    public function handleClosed(SubscriptionId $subscriptionId, string $message): void
    {
        $this->logger->debug('NIP-46 response subscription closed', [
            'request_id' => $this->requestId,
            'subscription_id' => (string) $subscriptionId,
            'message' => $message,
        ]);
    }

    public function handleNotice(RelayUrl $relayUrl, string $message): void
    {
        $this->logger->debug('NIP-46 response relay notice', [
            'request_id' => $this->requestId,
            'relay' => (string) $relayUrl,
            'message' => $message,
        ]);
    }

    public function isDone(): bool
    {
        return $this->signedEvent !== null || $this->error !== null;
    }

    /** @return array<string, mixed>|null */
    public function signedEvent(): ?array
    {
        return $this->signedEvent;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function clientPubkeyHex(): string
    {
        return $this->clientPubkeyHex;
    }
}
