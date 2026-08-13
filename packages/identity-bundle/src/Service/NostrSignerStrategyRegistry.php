<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Service;

use DecentNewsroom\IdentityBundle\Contract\NostrSignerStrategyInterface;

/**
 * Resolves the right {@see NostrSignerStrategyInterface} for a given user,
 * so callers (e.g. the relay gateway's AUTH-challenge handler) never need to
 * hardcode a specific signing transport (bunker/NIP-46, or whatever comes next).
 */
final class NostrSignerStrategyRegistry
{
    /**
     * @param iterable<NostrSignerStrategyInterface> $strategies tagged `identity.nostr_signer_strategy`
     */
    public function __construct(private readonly iterable $strategies)
    {
    }

    public function findFor(string $ownerId): ?NostrSignerStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($ownerId)) {
                return $strategy;
            }
        }

        return null;
    }

    public function getByMethod(string $method): ?NostrSignerStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->getMethod() === $method) {
                return $strategy;
            }
        }

        return null;
    }
}
