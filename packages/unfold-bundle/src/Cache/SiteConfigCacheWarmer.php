<?php

namespace DecentNewsroom\UnfoldBundle\Cache;

use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\PublicationRefreshInterface;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
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
        private readonly ?PublicationRefreshInterface $publicationRefresh = null,
    ) {}

    /**
     * Warm cache for a single UnfoldSite (SiteConfig + content)
     */
    public function warmPublicationSite(PublicationSite $site): bool
    {
        try {
            $this->publicationRefresh?->refresh($site->coordinate);

            $this->logger->info('Warming cache for UnfoldSite', [
                'subdomain' => $site->subdomain,
                'coordinate' => $site->coordinate,
            ]);

            // Invalidate the SiteConfig cache first so loadFromCoordinate fetches fresh data.
            //    Without this, a fresh/stale SWR entry would be returned as-is (the background
            //    register_shutdown_function refresh never fires in console commands).
            $this->logger->info('Invalidating existing SiteConfig cache...');
            $this->siteConfigLoader->invalidateFromCoordinate($site->coordinate);

            // Load and cache the SiteConfig (forced fresh fetch because we just invalidated)
            $this->logger->info('Loading SiteConfig from coordinate...');
            $siteConfig = $this->siteConfigLoader->loadFromCoordinate($site->coordinate);

            // Check if we got a placeholder
            if ($siteConfig->title === 'Loading...') {
                $this->logger->warning('Got placeholder SiteConfig - fetch may have failed', [
                    'subdomain' => $site->subdomain,
                    'coordinate' => $site->coordinate,
                ]);
                return false;
            }

            $this->logger->info('SiteConfig loaded', [
                'title' => $siteConfig->title,
                'categories_count' => count($siteConfig->categories),
            ]);

            // Invalidate all content caches so category/post fetches are forced fresh
            $this->logger->info('Invalidating existing content caches...');
            $this->contentProvider->invalidateSiteCache($siteConfig);

            // Warm categories cache (forced fresh fetch)
            $this->logger->info('Loading categories...');
            $categories = $this->contentProvider->getCategories($siteConfig);

            if (empty($categories)) {
                $this->logger->warning('No categories found - may be placeholder data');
            }

            // Warm category posts cache for each category
            foreach ($categories as $category) {
                $this->logger->info('Loading posts for category', ['category' => $category->slug]);
                $this->contentProvider->getCategoryPosts($category->coordinate);
            }

            // Warm home posts cache
            $this->logger->info('Loading home posts...');
            $this->contentProvider->getHomePosts($siteConfig, 3);

            $this->logger->info('Cache warmed successfully', [
                'subdomain' => $site->subdomain,
                'title' => $siteConfig->title,
                'categories' => count($categories),
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to warm cache for UnfoldSite', [
                'subdomain' => $site->subdomain,
                'coordinate' => $site->coordinate,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Invalidate cache for a single UnfoldSite
     */
    public function invalidatePublicationSite(PublicationSite $site): void
    {
        $this->siteConfigLoader->invalidateFromCoordinate($site->coordinate);
    }

    /**
     * Warm cache for multiple sites
     *
     * @param iterable<PublicationSite> $sites
     * @return array{success: int, failed: int}
     */
    public function warmAll(iterable $sites): array
    {
        $success = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($this->warmPublicationSite($site)) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * @param iterable<PublicationSite> $sites
     * @return array{success: int, failed: int}
     */
    public function warmAllPublicationSites(iterable $sites): array
    {
        $success = 0;
        $failed = 0;

        foreach ($sites as $site) {
            if ($this->warmPublicationSite($site)) {
                $success++;
            } else {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }
}
