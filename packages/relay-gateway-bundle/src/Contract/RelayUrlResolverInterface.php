<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Contract;

interface RelayUrlResolverInterface
{
    public function resolveToConnectionUrl(string $relayUrl): string;

    public function resolveForAuth(string $connectionRelayUrl): string;

    /**
     * @return string[]
     */
    public function getPrewarmRelayUrls(): array;
}