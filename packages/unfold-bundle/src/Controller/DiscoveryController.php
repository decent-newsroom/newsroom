<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Controller;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Http\RobotsService;
use DecentNewsroom\UnfoldBundle\Http\RssFeedService;
use DecentNewsroom\UnfoldBundle\Http\SitemapService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves publication discovery documents on Unfold publication hosts.
 */
final class DiscoveryController
{
    public function __construct(
        private readonly SiteConfigLoader $siteConfigLoader,
        private readonly ContentProvider $contentProvider,
        private readonly PublicationUrlGenerator $urls,
        private readonly RssFeedService $rssFeedService,
        private readonly SitemapService $sitemapService,
        private readonly RobotsService $robotsService,
    ) {
    }

    public function rss(Request $request): Response
    {
        [, $site] = $this->resolveSite($request);

        return $this->rssFeedService->createResponse(
            $site,
            $this->contentProvider->getPublicationPosts($site, 50),
        );
    }

    public function feed(Request $request): Response
    {
        $this->resolveSite($request);

        return new RedirectResponse(
            $this->urls->rss($request),
            Response::HTTP_PERMANENTLY_REDIRECT,
        );
    }

    public function categoryRss(Request $request, string $category): Response
    {
        [, $site] = $this->resolveSite($request);
        $categoryData = $this->findCategory($site, $category);

        if ($categoryData === null) {
            throw new NotFoundHttpException('Category not found');
        }

        return $this->rssFeedService->createResponse(
            $site,
            $this->contentProvider->getCategoryPosts($categoryData->coordinate),
            $categoryData->title . ' - ' . $site->title,
            $categoryData->summary !== '' ? $categoryData->summary : $site->description,
        );
    }

    public function sitemap(Request $request): Response
    {
        [, $site] = $this->resolveSite($request);

        return $this->sitemapService->createResponse(
            $site,
            $this->contentProvider->getCategories($site),
            $this->contentProvider->getPublicationPosts($site, 50),
        );
    }

    public function robots(Request $request): Response
    {
        $this->resolveSite($request);

        return $this->robotsService->createResponse();
    }

    /**
     * @return array{0: PublicationSite, 1: SiteConfig}
     */
    private function resolveSite(Request $request): array
    {
        $publicationSite = $request->attributes->get('_unfold_site');

        if (!$publicationSite instanceof PublicationSite) {
            throw new NotFoundHttpException('Site not found for this subdomain');
        }

        return [
            $publicationSite,
            $this->siteConfigLoader->loadFromCoordinate($publicationSite->coordinate),
        ];
    }

    private function findCategory(SiteConfig $site, string $slug): ?CategoryData
    {
        foreach ($this->contentProvider->getCategories($site) as $category) {
            if ($category->slug === $slug) {
                return $category;
            }
        }

        return null;
    }
}
