<?php

declare(strict_types=1);

namespace App\Service\Nostr;

/**
 * Host-owned relay set. It intentionally contains URLs, not transport objects.
 */
final class RelaySet
{
    /** @var RelayEndpoint[] */
    private array $relays = [];

    /** @param RelayEndpoint[] $relays */
    public function __construct(array $relays = [])
    {
        foreach ($relays as $relay) {
            $this->addRelay($relay);
        }
    }

    public function addRelay(RelayEndpoint $relay): void
    {
        foreach ($this->relays as $existing) {
            if ($existing->getUrl() === $relay->getUrl()) {
                return;
            }
        }

        $this->relays[] = $relay;
    }

    /** @return RelayEndpoint[] */
    public function getRelays(): array
    {
        return $this->relays;
    }

    /** @param RelayEndpoint[] $relays */
    public function setRelays(array $relays): void
    {
        $this->relays = [];
        foreach ($relays as $relay) {
            $this->addRelay($relay);
        }
    }

    /** @return string[] */
    public function getUrls(): array
    {
        return array_map(static fn (RelayEndpoint $relay): string => $relay->getUrl(), $this->relays);
    }
}
