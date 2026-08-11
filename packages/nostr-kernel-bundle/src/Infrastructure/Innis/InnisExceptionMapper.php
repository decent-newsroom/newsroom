<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

final readonly class InnisExceptionMapper
{
    public function map(\Throwable $throwable): \Throwable
    {
        // TODO(next pass): map innis/nostr-core exceptions to bundle-specific exceptions.
        return $throwable;
    }
}

