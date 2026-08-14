<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Contract;

use Innis\Nostr\Core\Domain\Entity\Event;

interface AuthChallengeSignerInterface
{
    public function signAuthChallenge(string $pubkeyHex, string $relayUrl, string $challenge, int $timeoutSeconds): ?Event;
}