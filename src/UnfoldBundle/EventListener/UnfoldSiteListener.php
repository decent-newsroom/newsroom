<?php

namespace App\UnfoldBundle\EventListener;

use App\Entity\UnfoldSite;
use App\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Listens for UnfoldSite entity changes and warms/invalidates cache accordingly
 */
#[AsEntityListener(event: Events::postPersist, entity: UnfoldSite::class)]
#[AsEntityListener(event: Events::postUpdate, entity: UnfoldSite::class)]
#[AsEntityListener(event: Events::preRemove, entity: UnfoldSite::class)]
class UnfoldSiteListener
{
    public function __construct(
        private readonly SiteConfigCacheWarmer $cacheWarmer,
    ) {}

    public function postPersist(UnfoldSite $site): void
    {
        // Don't warm cache synchronously on creation - it can cause timeouts
        // Cache will be populated on first request (SWR cache with placeholder fallback)
        // Or run: bin/console unfold:cache:warm manually/via cron
    }

    public function postUpdate(UnfoldSite $site): void
    {
        // Invalidate cache when site is updated
        // Cache will be warmed on first request (stale-while-revalidate will serve placeholder)
        $this->cacheWarmer->invalidateSite($site);

        // Don't warm synchronously - it can cause timeouts if relays are slow
        // Instead, rely on SWR cache to populate on first request
        // Or run: bin/console unfold:cache:warm manually/via cron
    }

    public function preRemove(UnfoldSite $site): void
    {
        // Invalidate cache before site is deleted
        $this->cacheWarmer->invalidateSite($site);
    }
}
