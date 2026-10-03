<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Controller;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Content\AmbiguousContentException;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Controller\SiteController;
use DecentNewsroom\UnfoldBundle\Http\HostResolver;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Http\RouteMatcher;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MultiKindSiteControllerTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ROOT = '30040:' . self::AUTHOR . ':root';

    public function testCanonicalRouteUsesFullCoordinateAndExactPrimaryCategory(): void
    {
        $post = new PostData('same.json', 'Wiki', '', 'Body', null, 1, self::AUTHOR, '30818:' . self::AUTHOR . ':same.json', kind: 30818);
        $site = $this->site();
        $wrong = new CategoryData('first', 'First', '30040:' . self::AUTHOR . ':first');
        $right = new CategoryData('second', 'Second', '30040:' . self::AUTHOR . ':second');
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('getCategories')->willReturn([$wrong, $right]);
        $provider->expects(self::once())->method('getPostByCoordinate')->with($post->coordinate, $site)->willReturn($post);
        $provider->expects(self::never())->method('getPost');
        $provider->method('getPublicationInventory')->willReturn([$post]);
        $provider->method('getCategoryPosts')->willReturnCallback(
            static fn(string $coordinate): array => $coordinate === $right->coordinate ? [$post] : [],
        );
        $builder = $this->createMock(ContextBuilder::class);
        $builder->expects(self::once())->method('buildPostContext')->with($site, [$wrong, $right], $post, $right, [$post])->willReturn(['post' => ['title' => 'Wiki']]);
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->expects(self::once())->method('render')->with('post', ['post' => ['title' => 'Wiki']])->willReturn('<article>Wiki</article>');
        $response = $this->controller($site, $provider, $builder, $renderer)($this->request(PublicationUrlGenerator::postPath($post)));
        self::assertSame('<article>Wiki</article>', $response->getContent());
    }

    public function testLegacyAmbiguityReturnsConflictAndNeverRenders(): void
    {
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('getCategories')->willReturn([]);
        $provider->method('getPost')->willThrowException(new AmbiguousContentException([]));
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->expects(self::never())->method('render');
        $this->expectException(ConflictHttpException::class);
        $this->controller($this->site(), $provider, $this->createMock(ContextBuilder::class), $renderer)($this->request('/a/duplicate'));
    }

    public function testUnlistedCanonicalIdentityReturnsNotFoundWithoutSlugFallback(): void
    {
        $post = new PostData('same', 'Unlisted', '', 'Body', null, 1, self::AUTHOR, '30817:' . self::AUTHOR . ':same', kind: 30817);
        $provider = $this->createMock(ContentProvider::class);
        $provider->method('getCategories')->willReturn([]);
        $provider->expects(self::once())->method('getPostByCoordinate')->with($post->coordinate, $this->site())->willReturn(null);
        $provider->expects(self::never())->method('getPost');
        $this->expectException(NotFoundHttpException::class);
        $this->controller($this->site(), $provider, $this->createMock(ContextBuilder::class), $this->createMock(HandlebarsRenderer::class))($this->request(PublicationUrlGenerator::postPath($post)));
    }

    public function testScopedRootStopsBeforeContentAndTheme(): void
    {
        $site = new SiteConfig(self::ROOT, 'Private', '', null, [], self::AUTHOR, isScoped: true);
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::never())->method('getCategories');
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->expects(self::never())->method('setTheme');
        $this->expectException(NotFoundHttpException::class);
        $this->controller($site, $provider, $this->createMock(ContextBuilder::class), $renderer)($this->request('/'));
    }

    private function site(): SiteConfig
    {
        return new SiteConfig(self::ROOT, 'Publication', '', null, [], self::AUTHOR);
    }

    private function request(string $path): Request
    {
        $request = Request::create('https://publication.example' . $path);
        $request->attributes->set('_unfold_site', new PublicationSite('publication', self::ROOT));

        return $request;
    }

    private function controller(SiteConfig $site, ContentProvider $provider, ContextBuilder $builder, HandlebarsRenderer $renderer): SiteController
    {
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->method('loadFromCoordinate')->willReturn($site);

        return new SiteController($this->createMock(HostResolver::class), $loader, $provider, new RouteMatcher(), $builder, $renderer, new NullLogger());
    }
}
