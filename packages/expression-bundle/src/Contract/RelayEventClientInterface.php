<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Contract;

interface RelayEventClientInterface
{
    /**
     * @param int[] $kinds
     * @param array<string, mixed> $filter
     * @param string[] $relayUrls
     * @return EventInterface[]
     */
    public function fetch(array $kinds, array $filter, array $relayUrls = [], ?string $pubkey = null): array;
}
