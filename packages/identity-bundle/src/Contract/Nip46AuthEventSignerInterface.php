<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Contract;

/**
 * Transitional seam for server-side NIP-42 AUTH signing through a NIP-46
 * remote signer. The IdentityBundle strategy depends on this contract so the
 * host can keep its current relay transport while the auth gateway is moved
 * behind the bundle's signer registry.
 */
interface Nip46AuthEventSignerInterface
{
    /**
     * @param array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: string[]} $session
     * @return array<string,mixed>|null
     */
    public function signAuthEvent(
        string $userPubkeyHex,
        string $relayUrl,
        string $challenge,
        array $session,
        int $timeoutSeconds = 15,
    ): ?array;
}