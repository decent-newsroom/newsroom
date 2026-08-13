<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Auth;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

/**
 * Wraps a claimed NIP-98 (kind 27235) HTTP auth event together with the
 * expected request claims (method + URL) it must match, and the maximum
 * age it may have. The full {@see NostrEvent} (not just a few extracted
 * fields) is required so the validator can recompute the event id from
 * every signed field (pubkey, created_at, kind, tags, content) before
 * verifying the signature — trusting only the client-supplied `id`/`sig`
 * pair without recomputing the id would allow tampering with tags/content
 * while keeping a stale, still-"valid" signature.
 */
final readonly class NostrHttpAuthToken
{
    public function __construct(
        private NostrEvent $event,
        private string $expectedMethod,
        private string $expectedUrl,
        private int $maxAgeSeconds = 60,
    ) {
    }

    public function event(): NostrEvent
    {
        return $this->event;
    }

    public function expectedMethod(): string
    {
        return $this->expectedMethod;
    }

    public function expectedUrl(): string
    {
        return $this->expectedUrl;
    }

    public function maxAgeSeconds(): int
    {
        return $this->maxAgeSeconds;
    }
}

