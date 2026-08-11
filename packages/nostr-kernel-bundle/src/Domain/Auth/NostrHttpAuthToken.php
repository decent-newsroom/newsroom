<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Auth;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventSignature;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;

final readonly class NostrHttpAuthToken
{
    public function __construct(
        private string $method,
        private string $url,
        private Pubkey $pubkey,
        private int $createdAt,
        private EventSignature $signature,
    ) {
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function pubkey(): Pubkey
    {
        return $this->pubkey;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function signature(): EventSignature
    {
        return $this->signature;
    }
}

