<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Service;

use DecentNewsroom\RelayGatewayBundle\Contract\RelayUrlResolverInterface;

final class PassthroughRelayUrlResolver implements RelayUrlResolverInterface
{
    public function resolveToConnectionUrl(string $relayUrl): string
    {
        return $relayUrl;
    }

    public function resolveForAuth(string $connectionRelayUrl): string
    {
        return $connectionRelayUrl;
    }

    public function getPrewarmRelayUrls(): array
    {
        return [];
    }
}