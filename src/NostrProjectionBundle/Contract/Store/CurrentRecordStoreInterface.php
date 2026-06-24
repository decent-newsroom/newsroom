<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Contract\Store;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrProjectionBundle\Domain\Event\CurrentRecord;

interface CurrentRecordStoreInterface
{
    /**
     * Returns null for non-replaceable/non-addressable events.
     */
    public function upsertIfCurrent(NostrEvent $event): ?CurrentRecord;
}
