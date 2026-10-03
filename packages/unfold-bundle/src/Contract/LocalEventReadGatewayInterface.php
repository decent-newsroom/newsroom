<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

/**
 * Reads persisted events only; missing events must not trigger relay requests.
 */
interface LocalEventReadGatewayInterface
{
    public function findLocalByCoordinate(string $coordinate): ?NostrEvent;

    /**
     * @param list<string> $coordinates
     * @return array<string, NostrEvent>
     */
    public function findLocalByCoordinates(array $coordinates): array;
}
