<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Helper\NavigationBuilderTrait;
use App\Twig\UnfoldAdminExtension;
use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Admin\PublicationMount;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class UnfoldAdminExtensionTest extends TestCase
{
    public function testPublicationNavigationMarksOnlyTheCurrentPublicationPageActive(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/magazine-alpha/admin/settings'));

        $navigation = (new UnfoldAdminExtension($requestStack))->publicationNavigation($this->publication('/magazine-alpha/admin'));

        self::assertSame('/magazine-alpha/admin', $navigation[0]['items'][0]['href']);
        self::assertSame('/magazine-alpha/admin/settings', $navigation[0]['items'][1]['href']);
        self::assertFalse($navigation[0]['items'][0]['active']);
        self::assertTrue($navigation[0]['items'][1]['active']);

        $otherPublication = (new UnfoldAdminExtension($requestStack))->publicationNavigation($this->publication('/magazine-beta/admin'));
        self::assertFalse($otherPublication[0]['items'][0]['active']);
        self::assertFalse($otherPublication[0]['items'][1]['active']);
    }

    public function testPublicationNavigationNormalizesTrailingSlashesForBothMounts(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/mag-alpha/admin/'));

        $navigation = (new UnfoldAdminExtension($requestStack))->publicationNavigation($this->publication('/mag-alpha/admin/'));

        self::assertSame('/mag-alpha/admin', $navigation[0]['items'][0]['href']);
        self::assertTrue($navigation[0]['items'][0]['active']);

        $requestStack->pop();
        $requestStack->push(Request::create('/admin/settings'));
        $navigation = (new UnfoldAdminExtension($requestStack))->publicationNavigation($this->publication('/admin/'));

        self::assertSame('/admin/settings', $navigation[0]['items'][1]['href']);
        self::assertTrue($navigation[0]['items'][1]['active']);
    }

    public function testSidebarNavigationBuilderKeepsParameterizedRouteItems(): void
    {
        $builder = new class {
            use NavigationBuilderTrait;

            public function build(): array
            {
                return $this->buildNewsroomNav();
            }
        };

        $navigation = $builder->build();

        self::assertSame('my_magazines', $navigation[1]['items'][0]['route']);
        self::assertArrayNotHasKey('href', $navigation[1]['items'][0]);
        self::assertArrayNotHasKey('params', $navigation[1]['items'][0]);
    }

    public function testSidebarTemplateSupportsExplicitHrefAndParameterizedRouteFallback(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../templates'));
        $twig->addFilter(new \Twig\TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static function (string $route, array $params = []): string {
            return '/generated/' . $route . '/' . ($params['publication'] ?? 'missing');
        }));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addGlobal('app', (object) ['current_route' => 'legacy_route']);

        $html = $twig->render('components/SidebarNav.html.twig', [
            'attributes' => new SidebarNavAttributes(),
            'backLink' => null,
            'footerComponent' => null,
            'sections' => [[
                'label' => 'section',
                'items' => [
                    ['label' => 'explicit', 'href' => '/magazine-alpha/admin', 'active' => true],
                    ['label' => 'parameterized', 'route' => 'publication_admin', 'params' => ['publication' => 'alpha']],
                ],
            ]],
        ]);

        self::assertStringContainsString('href="/magazine-alpha/admin" aria-current="page"', $html);
        self::assertStringContainsString('href="/generated/publication_admin/alpha"', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    private function publication(string $adminPathPrefix): PublicationContext
    {
        return new PublicationContext(
            '30040:' . str_repeat('a', 64) . ':publication',
            new PublicationSettings('30040:' . str_repeat('a', 64) . ':publication'),
            PublicationMount::SUBDOMAIN,
            $adminPathPrefix,
        );
    }
}

final class SidebarNavAttributes
{
    public function defaults(array $defaults): self
    {
        return $this;
    }

    public function __toString(): string
    {
        return '';
    }
}
