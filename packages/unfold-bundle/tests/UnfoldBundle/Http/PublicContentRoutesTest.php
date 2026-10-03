<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Http;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentKindPolicy;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Http\RouteMatcher;
use DecentNewsroom\UnfoldBundle\Http\RssFeedService;
use DecentNewsroom\UnfoldBundle\Http\SitemapService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;

final class PublicContentRoutesTest extends TestCase
{
    /** @dataProvider routes */
    public function testCanonicalPathRoundTripPreservesFullIdentity(int $kind, string $slug): void
    {
        $post = $this->post($kind, $slug);
        $path = PublicationUrlGenerator::postPath($post);
        self::assertStringContainsString('/' . ContentKindPolicy::segment($kind) . '/' . rawurlencode($slug), $path);
        $request = Request::create('https://publication.example' . $path);
        $routes = new RouteCollection();
        $routes->add('unfold_site', new Route('/{path}', [], ['path' => '.*']));
        self::assertSame('unfold_site', (new UrlMatcher($routes, new RequestContext()))->match($request->getPathInfo())['_route']);
        $route = (new RouteMatcher())->match($request->getPathInfo(), $this->site(), []);
        self::assertSame(RouteMatcher::PAGE_POST, $route['type']);
        self::assertSame($post->coordinate, $route['coordinate']);
        self::assertSame($slug, $route['slug']);
    }

    public function routes(): iterable
    {
        foreach (ContentKindPolicy::KINDS as $kind) {
            foreach (['Case:日本語/with space.json', '100%literal%2F.xml', 'file.css', 'plain', 'trailing/'] as $slug) {
                yield $kind . '-' . $slug => [$kind, $slug];
            }
        }
    }

    public function testLegacyCategoryAndReservedRoutesRemainAvailable(): void
    {
        $matcher = new RouteMatcher();
        $categories = [new CategoryData('wiki', 'Wiki', '30040:owner:wiki'), new CategoryData('admin', 'Admin', '30040:owner:admin')];
        self::assertSame(RouteMatcher::PAGE_CATEGORY, $matcher->match('/wiki', $this->site(), $categories)['type']);
        self::assertSame(RouteMatcher::PAGE_NOT_FOUND, $matcher->match('/admin', $this->site(), $categories)['type']);
        self::assertSame(RouteMatcher::PAGE_ABOUT, $matcher->match('/about', $this->site(), $categories)['type']);
        self::assertSame(RouteMatcher::PAGE_HOME, $matcher->match('/', $this->site(), $categories)['type']);
        self::assertSame('hello.xml', $matcher->match('/a/hello.xml', $this->site(), $categories)['slug']);
        self::assertSame(RouteMatcher::PAGE_NOT_FOUND, $matcher->match('/favicon.ico', $this->site(), [])['type']);
        self::assertSame(RouteMatcher::PAGE_NOT_FOUND, $matcher->match('/npub1broken/wiki/topic', $this->site(), [])['type']);
    }

    public function testFeedsAndSitemapUseSameCanonicalUrlsAndExcludeScopedOrUnsupportedPosts(): void
    {
        $requests = new RequestStack();
        $requests->push(Request::create('https://publication.example/rss.xml'));
        $urls = new PublicationUrlGenerator($requests);
        $posts = array_map(fn(int $kind): PostData => $this->post($kind, 'same.json'), ContentKindPolicy::KINDS);
        $posts[] = $this->post(30817, 'private', [['s', 'members']]);
        $posts[] = $this->post(30024, 'draft');
        $rss = (new RssFeedService($urls))->createResponse($this->site(), $posts)->getContent();
        $sitemap = (new SitemapService($urls))->createResponse($this->site(), [], $posts)->getContent();
        self::assertSame(4, substr_count($rss, '<item>'));
        self::assertSame(6, substr_count($sitemap, '<url>'));
        foreach (array_slice($posts, 0, 4) as $post) {
            self::assertStringContainsString($urls->post($post), $rss);
            self::assertStringContainsString($urls->post($post), $sitemap);
        }
        self::assertStringNotContainsString('private', $rss . $sitemap);
        self::assertStringNotContainsString('draft', $rss . $sitemap);
    }

    private function post(int $kind, string $slug, array $tags = []): PostData
    {
        $author = str_repeat('a', 64);

        return new PostData($slug, 'Title', '', 'Body', null, 1, $author, $kind . ':' . $author . ':' . $slug, kind: $kind, tags: $tags);
    }

    private function site(): SiteConfig
    {
        return new SiteConfig('30040:' . str_repeat('a', 64) . ':root', 'Publication', '', null, [], str_repeat('a', 64));
    }
}
