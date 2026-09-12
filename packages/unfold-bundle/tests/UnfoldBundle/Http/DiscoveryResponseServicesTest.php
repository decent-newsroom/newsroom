<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Http;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Http\RobotsService;
use DecentNewsroom\UnfoldBundle\Http\RssFeedService;
use DecentNewsroom\UnfoldBundle\Http\SitemapService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class DiscoveryResponseServicesTest extends TestCase
{
    private PublicationUrlGenerator $urls;

    protected function setUp(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('https://publication.example.test/rss.xml'));
        $this->urls = new PublicationUrlGenerator($requests);
    }

    public function testRssUsesCurrentPublicationHostAndEscapesValues(): void
    {
        $site = new SiteConfig(
            naddr: '30040:owner:publication',
            title: 'A & <Publication>',
            description: 'Description',
            logo: 'https://cdn.example.test/logo.png',
            categories: [],
            pubkey: 'owner',
        );
        $post = new PostData(
            slug: 'hello-world',
            title: 'Post <one>',
            summary: 'Summary & details',
            content: 'Content',
            image: 'https://cdn.example.test/post.png',
            publishedAt: 1_700_000_000,
            pubkey: 'a' . str_repeat('b', 63),
            coordinate: '30023:' . 'a' . str_repeat('b', 63) . ':hello-world',
        );

        $response = (new RssFeedService($this->urls))->createResponse($site, [$post]);
        $body = $response->getContent();

        self::assertSame('application/rss+xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('max-age=600, public', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('<title>A &amp; &lt;Publication&gt;</title>', $body);
        self::assertStringContainsString('<link>https://publication.example.test/a/hello-world</link>', $body);
        self::assertStringContainsString('<guid isPermaLink="false">30023:', $body);
        self::assertStringContainsString('<author>' . $post->pubkey . '</author>', $body);
        self::assertStringContainsString('https://cdn.example.test/post.png', $body);
        self::assertSame(1, substr_count($body, '<item>'));
    }

    public function testSitemapDeduplicatesPublicationUrls(): void
    {
        $site = new SiteConfig('30040:owner:publication', 'Publication', '', null, [], 'owner');
        $category = new CategoryData('news', 'News', '30040:owner:news');
        $post = new PostData('story', 'Story', '', '', null, 1, 'author', '30023:author:story');

        $response = (new SitemapService($this->urls))->createResponse(
            $site,
            [$category, $category],
            [$post, $post],
        );
        $body = $response->getContent();

        self::assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('max-age=600, public', $response->headers->get('Cache-Control'));
        self::assertSame(3, substr_count($body, '<url>'));
        self::assertStringContainsString('<loc>https://publication.example.test/</loc>', $body);
        self::assertStringContainsString('<loc>https://publication.example.test/news</loc>', $body);
        self::assertStringContainsString('<loc>https://publication.example.test/a/story</loc>', $body);
    }

    public function testRobotsPointsToCurrentHostSitemap(): void
    {
        $response = (new RobotsService($this->urls))->createResponse();

        self::assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('max-age=600, public', $response->headers->get('Cache-Control'));
        self::assertSame(
            "User-agent: *\nAllow: /\nSitemap: https://publication.example.test/sitemap.xml\n",
            $response->getContent(),
        );
    }
}
