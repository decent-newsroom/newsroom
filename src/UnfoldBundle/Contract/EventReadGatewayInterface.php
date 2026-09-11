<?php

declare(strict_types=1);

namespace App\UnfoldBundle\Contract;

interface EventReadGatewayInterface
{
    /**
     * @param list<string> $relayHints
     */
    public function findByCoordinate(string $coordinate, array $relayHints = []): ?NostrEvent;

    /**
     * @param list<string> $coordinates
     * @return array<string, NostrEvent>
     */
    public function findByCoordinates(array $coordinates): array;
}
