<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Contract\Store;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrProjectionBundle\Domain\Event\StoredRawEvent;

interface RawEventStoreInterface
{
    public function upsert(NostrEvent $event, ?string $sourceRelay = null): StoredRawEvent;
}
