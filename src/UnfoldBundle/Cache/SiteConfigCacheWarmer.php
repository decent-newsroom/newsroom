<?php

namespace App\UnfoldBundle\Cache;

use App\Entity\UnfoldSite;
use App\UnfoldBundle\Config\SiteConfigLoader;
use Psr\Log\LoggerInterface;

/**
 * Warms the SiteConfig cache for UnfoldSite entities
 */
class SiteConfigCacheWarmer
{
    public function __construct(
        private readonly SiteConfigLoader $siteConfigLoader,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Warm cache for a single UnfoldSite
     */
    public function warmSite(UnfoldSite $site): bool
    {
        try {
            $this->logger->info('Warming cache for UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'naddr' => $site->getNaddr(),
            ]);

            // This will load and cache the SiteConfig
            $this->siteConfigLoader->loadFromMagazine($site->getNaddr());

            $this->logger->info('Cache warmed successfully', [
                'subdomain' => $site->getSubdomain(),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to warm cache for UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'naddr' => $site->getNaddr(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Invalidate cache for a single UnfoldSite
     */
    public function invalidateSite(UnfoldSite $site): void
    {
        $this->siteConfigLoader->invalidateFromMagazine($site->getNaddr());
    }

    /**
     * Warm cache for multiple sites
     *
     * @param iterable<UnfoldSite> $sites
     * @return array{success: int, failed: int}
     */
    public function warmAll(iterable $sites): array
    {
        $success = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($this->warmSite($site)) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }
}
