<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

interface RelayAuthSignerInterface
{
    public function supportsRelayAuth(string $subjectPubkeyHex): bool;

    /** @return array<string, mixed>|null */
    public function signRelayAuth(
        string $subjectPubkeyHex,
        string $relayUrl,
        string $challenge,
        ?int $timeoutSeconds = null,
    ): ?array;
}

