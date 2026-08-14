<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

use DecentNewsroom\SigningBundle\Contract\NostrSignerStrategyInterface;

final readonly class NostrSignerStrategyRegistry
{
    /** @var list<NostrSignerStrategyInterface> */
    private array $strategies;

    /** @param iterable<NostrSignerStrategyInterface> $strategies */
    public function __construct(iterable $strategies)
    {
        $this->strategies = $strategies instanceof \Traversable ? iterator_to_array($strategies, false) : array_values($strategies);
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
