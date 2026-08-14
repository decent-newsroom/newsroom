<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Contract;

use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;

interface Nip46EventSignerInterface
{
    /**
     * @param array<string, mixed> $unsignedEvent
     * @return array<string, mixed>|null
     */
    public function signEvent(
        string $subjectPubkeyHex,
        array $unsignedEvent,
        RemoteSignerSession $session,
        ?int $timeoutSeconds = null,
    ): ?array;
}
