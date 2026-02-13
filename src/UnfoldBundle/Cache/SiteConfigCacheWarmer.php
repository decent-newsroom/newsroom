<?php

namespace App\UnfoldBundle\Cache;

use App\Entity\UnfoldSite;
use App\UnfoldBundle\Config\SiteConfigLoader;
use App\UnfoldBundle\Content\ContentProvider;
use Psr\Log\LoggerInterface;

/**
 * Warms the SiteConfig and content cache for UnfoldSite entities
 */
class SiteConfigCacheWarmer
{
    public function __construct(
        private readonly SiteConfigLoader $siteConfigLoader,
        private readonly ContentProvider $contentProvider,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Warm cache for a single UnfoldSite (SiteConfig + content)
     */
    public function warmSite(UnfoldSite $site): bool
    {
        try {
            $this->logger->info('Warming cache for UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'coordinate' => $site->getCoordinate(),
            ]);

            // 1. Load and cache the SiteConfig
            $this->logger->info('Loading SiteConfig from coordinate...');
            $siteConfig = $this->siteConfigLoader->loadFromCoordinate($site->getCoordinate());

            // Check if we got a placeholder
            if ($siteConfig->title === 'Loading...') {
                $this->logger->warning('Got placeholder SiteConfig - fetch may have failed', [
                    'subdomain' => $site->getSubdomain(),
                    'coordinate' => $site->getCoordinate(),
                ]);
                return false;
            }

            $this->logger->info('SiteConfig loaded', [
                'title' => $siteConfig->title,
                'categories_count' => count($siteConfig->categories),
            ]);

            // 2. Warm categories cache
            $this->logger->info('Loading categories...');
            $categories = $this->contentProvider->getCategories($siteConfig);

            if (empty($categories)) {
                $this->logger->warning('No categories found - may be placeholder data');
            }

            // 3. Warm category posts cache for each category
            foreach ($categories as $category) {
                $this->logger->info('Loading posts for category', ['category' => $category->slug]);
                $this->contentProvider->getCategoryPosts($category->coordinate);
            }

            // 4. Warm home posts cache
            $this->logger->info('Loading home posts...');
            $this->contentProvider->getHomePosts($siteConfig, 3);

            $this->logger->info('Cache warmed successfully', [
                'subdomain' => $site->getSubdomain(),
                'title' => $siteConfig->title,
                'categories' => count($categories),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to warm cache for UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'coordinate' => $site->getCoordinate(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Invalidate cache for a single UnfoldSite
     */
    public function invalidateSite(UnfoldSite $site): void
    {
        $this->siteConfigLoader->invalidateFromCoordinate($site->getCoordinate());
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
