<?php

declare(strict_types=1);

namespace App\Service\Nostr;

/**
 * Value object used by host-owned relay selection APIs.
 *
 * Transport details deliberately do not leak out of the application boundary.
 */
final readonly class RelayEndpoint
{
    public function __construct(private string $url)
    {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
