<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Controller;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Controller\DiscoveryController;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Http\RobotsService;
use DecentNewsroom\UnfoldBundle\Http\RssFeedService;
use DecentNewsroom\UnfoldBundle\Http\SitemapService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DiscoveryControllerTest extends TestCase
{
    private RequestStack $requests;

    protected function setUp(): void
    {
        $this->requests = new RequestStack();
    }

    public function testFeedRedirectsToCanonicalRssOnTheCurrentPublicationHost(): void
    {
        $site = $this->site();
        $publication = $this->publicationSite();
        $loader = $this->createLoader($publication, $site);
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::never())->method('getPublicationPosts');

        $response = $this->controller($loader, $provider)->feed(
            $this->request('/feed.xml', $publication),
        );

        self::assertSame(Response::HTTP_PERMANENTLY_REDIRECT, $response->getStatusCode());
        self::assertSame(
            'https://publication.example.test/rss.xml',
            $response->headers->get('Location'),
        );
    }

    public function testRssRequestsThePublicationWideProviderLimit(): void
    {
        $site = $this->site();
        $publication = $this->publicationSite();
        $loader = $this->createLoader($publication, $site);
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::once())
            ->method('getPublicationPosts')
            ->with($site, 50)
            ->willReturn([]);

        $response = $this->controller($loader, $provider)->rss(
            $this->request('/rss.xml', $publication),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<title>Publication</title>', $response->getContent());
    }

    public function testSitemapRequestsCategoriesAndThePublicationWideProviderLimit(): void
    {
        $site = $this->site();
        $publication = $this->publicationSite();
        $category = new CategoryData('news', 'News', '30040:owner:news');
        $loader = $this->createLoader($publication, $site);
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::once())
            ->method('getCategories')
            ->with($site)
            ->willReturn([$category]);
        $provider->expects(self::once())
            ->method('getPublicationPosts')
            ->with($site, 50)
            ->willReturn([]);

        $response = $this->controller($loader, $provider)->sitemap(
            $this->request('/sitemap.xml', $publication),
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString(
            '<loc>https://publication.example.test/news</loc>',
            $response->getContent(),
        );
    }

    public function testUnknownCategoryReturnsNotFound(): void
    {
        $site = $this->site();
        $publication = $this->publicationSite();
        $loader = $this->createLoader($publication, $site);
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::once())
            ->method('getCategories')
            ->with($site)
            ->willReturn([
                new CategoryData('news', 'News', '30040:owner:news'),
            ]);
        $provider->expects(self::never())->method('getCategoryPosts');

        $this->expectException(NotFoundHttpException::class);
        $this->controller($loader, $provider)->categoryRss(
            $this->request('/missing/rss.xml', $publication),
            'missing',
        );
    }

    public function testCategoryRssUsesCategoryTitleAndPublicationDescriptionAsFallback(): void
    {
        $site = $this->site(description: 'Publication description');
        $publication = $this->publicationSite();
        $category = new CategoryData(
            'news',
            'News',
            '30040:owner:news',
            summary: '',
        );
        $loader = $this->createLoader($publication, $site);
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::once())
            ->method('getCategories')
            ->with($site)
            ->willReturn([$category]);
        $provider->expects(self::once())
            ->method('getCategoryPosts')
            ->with($category->coordinate)
            ->willReturn([]);

        $response = $this->controller($loader, $provider)->categoryRss(
            $this->request('/news/rss.xml', $publication),
            'news',
        );
        $body = $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<title>News - Publication</title>', $body);
        self::assertStringContainsString('<description>Publication description</description>', $body);
    }

    public function testDiscoveryRequiresPublicationSiteRequestAttribute(): void
    {
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::never())->method('loadFromCoordinate');
        $provider = $this->createMock(ContentProvider::class);

        $this->expectException(NotFoundHttpException::class);
        $this->controller($loader, $provider)->rss(Request::create(
            'https://publication.example.test/rss.xml',
        ));
    }

    private function controller(SiteConfigLoader $loader, ContentProvider $provider): DiscoveryController
    {
        return new DiscoveryController(
            siteConfigLoader: $loader,
            contentProvider: $provider,
            urls: new PublicationUrlGenerator($this->requests),
            rssFeedService: new RssFeedService(new PublicationUrlGenerator($this->requests)),
            sitemapService: new SitemapService(new PublicationUrlGenerator($this->requests)),
            robotsService: new RobotsService(new PublicationUrlGenerator($this->requests)),
        );
    }

    private function createLoader(PublicationSite $publication, SiteConfig $site): SiteConfigLoader
    {
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())
            ->method('loadFromCoordinate')
            ->with($publication->coordinate)
            ->willReturn($site);

        return $loader;
    }

    private function request(string $path, PublicationSite $publication): Request
    {
        $request = Request::create('https://publication.example.test' . $path);
        $request->attributes->set('_unfold_site', $publication);
        $this->requests->push($request);

        return $request;
    }

    private function publicationSite(): PublicationSite
    {
        return new PublicationSite('publication', '30040:owner:publication');
    }

    private function site(string $description = 'Description'): SiteConfig
    {
        return new SiteConfig(
            naddr: '30040:owner:publication',
            title: 'Publication',
            description: $description,
            logo: null,
            categories: ['30040:owner:news'],
            pubkey: 'owner',
        );
    }
}
