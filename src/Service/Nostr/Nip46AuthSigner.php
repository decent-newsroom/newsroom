<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use DecentNewsroom\IdentityBundle\Contract\Nip46AuthEventSignerInterface as LegacyNip46AuthEventSignerInterface;
use DecentNewsroom\SigningBundle\Contract\Nip46AuthEventSignerInterface as SigningNip46AuthEventSignerInterface;

/**
 * @deprecated Use DecentNewsroom\SigningBundle\Contract\Nip46AuthEventSignerInterface directly.
 */
final readonly class Nip46AuthSigner implements LegacyNip46AuthEventSignerInterface
{
    public function __construct(private SigningNip46AuthEventSignerInterface $inner)
    {
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
        int $timeoutSeconds = 15,
    ): ?array {
        return $this->inner->signAuthEvent($userPubkeyHex, $relayUrl, $challenge, $session, $timeoutSeconds);
    }
}
