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
        // Warm cache when a new site is created
        $this->cacheWarmer->warmSite($site);
    }

    public function postUpdate(UnfoldSite $site): void
    {
        // Invalidate old cache and warm new cache when site is updated
        $this->cacheWarmer->invalidateSite($site);
        $this->cacheWarmer->warmSite($site);
    }

    public function preRemove(UnfoldSite $site): void
    {
        // Invalidate cache before site is deleted
        $this->cacheWarmer->invalidateSite($site);
    }
}
