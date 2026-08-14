<?php

declare(strict_types=1);

namespace App\RelayGateway;

use App\Service\Nostr\RelayRegistry;
use App\Util\RelayUrlNormalizer;
use DecentNewsroom\RelayGatewayBundle\Contract\RelayUrlResolverInterface;

final readonly class RelayUrlResolverAdapter implements RelayUrlResolverInterface
{
    public function __construct(private RelayRegistry $relayRegistry)
    {
    }

    public function resolveToConnectionUrl(string $relayUrl): string
    {
        return $this->relayRegistry->resolveToLocalUrl($relayUrl);
    }

    public function resolveForAuth(string $connectionRelayUrl): string
    {
        $localRelay = $this->relayRegistry->getLocalRelay();
        if ($localRelay !== null && RelayUrlNormalizer::equals($connectionRelayUrl, $localRelay)) {
            return $this->relayRegistry->getPublicUrl() ?? $connectionRelayUrl;
        }

        return $connectionRelayUrl;
    }

    /**
     * @return string[]
     */
    public function getPrewarmRelayUrls(): array
    {
        return array_values(array_unique(array_map(
            $this->resolveToConnectionUrl(...),
            $this->relayRegistry->getDefaultRelays(),
        )));
    }
}
