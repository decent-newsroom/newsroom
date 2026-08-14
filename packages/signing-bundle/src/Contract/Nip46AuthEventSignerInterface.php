<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

interface Nip46AuthEventSignerInterface
{
    /**
     * @param array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: list<string>} $session
     * @return array<string, mixed>|null
     */
    public function signAuthEvent(
        string $userPubkeyHex,
        string $relayUrl,
        string $challenge,
        array $session,
        int $timeoutSeconds = 15,
    ): ?array;
}
