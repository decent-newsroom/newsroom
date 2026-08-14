<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

use DecentNewsroom\SigningBundle\Contract\Nip46EventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\NostrEventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\NostrSignerStrategyInterface;
use DecentNewsroom\SigningBundle\Contract\RelayAuthSignerInterface;
use Psr\Log\LoggerInterface;

final readonly class RemoteBunkerSignerStrategy implements NostrSignerStrategyInterface, NostrEventSignerInterface, RelayAuthSignerInterface
{
    public const METHOD = 'nip46';

    public function __construct(
        private Nip46SessionStore $sessions,
        private Nip46EventSignerInterface $eventSigner,
        private RelayAuthEventFactory $authEventFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function getMethod(): string
    {
        return self::METHOD;
    }

    public function supports(string $ownerId): bool
    {
        return $this->sessions->has($ownerId);
    }

    public function supportsRelayAuth(string $subjectPubkeyHex): bool
    {
        return $this->supports($subjectPubkeyHex);
    }

    /**
     * @param array<string, mixed> $unsignedEvent
     * @return array<string, mixed>|null
     */
    public function sign(string $ownerId, array $unsignedEvent, ?int $timeoutSeconds = null): ?array
    {
        $session = $this->sessions->getSession($ownerId);
        if ($session === null) {
            return null;
        }

        try {
            $signedEvent = $this->eventSigner->signEvent($ownerId, $unsignedEvent, $session, $timeoutSeconds);
        } catch (\Throwable $e) {
            $this->logger->warning('Remote bunker signing failed', [
                'owner' => substr($ownerId, 0, 8).'...',
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($signedEvent !== null) {
            $this->sessions->refresh($ownerId);
        }

        return $signedEvent;
    }

    /** @return array<string, mixed>|null */
    public function signRelayAuth(
        string $subjectPubkeyHex,
        string $relayUrl,
        string $challenge,
        ?int $timeoutSeconds = null,
    ): ?array {
        return $this->sign(
            $subjectPubkeyHex,
            $this->authEventFactory->create($subjectPubkeyHex, $relayUrl, $challenge),
            $timeoutSeconds,
        );
    }
}

