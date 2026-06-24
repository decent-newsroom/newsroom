<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;

final readonly class InnisEventMapper
{
    /**
     * @param array<string, mixed> $rawEvent
     */
    public function map(array $rawEvent): NostrEvent
    {
        // TODO(next pass): map innis/nostr-core event representations to domain NostrEvent.
        throw new \LogicException('Innis event mapper is not wired yet; inspect innis/nostr-core APIs in the next pass.');
    }
}

