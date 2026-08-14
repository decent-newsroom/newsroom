<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Service;

use DecentNewsroom\RelayGatewayBundle\Contract\AuthChallengeSignerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;

final class NullAuthChallengeSigner implements AuthChallengeSignerInterface
{
    public function signAuthChallenge(string $pubkeyHex, string $relayUrl, string $challenge, int $timeoutSeconds): ?Event
    {
        return null;
    }
}