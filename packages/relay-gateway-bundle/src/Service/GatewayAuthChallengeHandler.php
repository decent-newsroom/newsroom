<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Service;

use DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;

final readonly class GatewayAuthChallengeHandler implements AuthChallengeHandlerInterface
{
    public function __construct(
        private ?string $pubkeyHex,
        private AuthChallengeSignerInterface $userSigner,
        private int $timeoutSeconds,
        private ?string $authRelayUrl = null,
    ) {
    }

    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
    {
        if ($this->pubkeyHex === null || $this->pubkeyHex === '') {
            return null;
        }

        return $this->userSigner->signAuthChallenge(
            $this->pubkeyHex,
            $this->authRelayUrl ?? (string) $relayUrl,
            $challenge,
            $this->timeoutSeconds,
        );
    }
}
