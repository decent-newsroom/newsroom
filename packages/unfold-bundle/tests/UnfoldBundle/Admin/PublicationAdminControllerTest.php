<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Admin\PublicationMount;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Controller\Admin\PublicationAdminController;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PublicationAdminControllerTest extends TestCase
{
    private const COORDINATE = '30040:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:edition';

    /** @dataProvider mounts */
    public function testSettingsSaveFiltersBlankRowsAndRedirectsToItsOwnMount(PublicationMount $mount, string $prefix): void
    {
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::once())->method('savePresentation')->with(
            self::COORDINATE,
            'default',
            [
                ['label' => 'About', 'url' => 'https://example.test/about'],
                ['label' => 'Contact', 'url' => 'https://example.test/contact'],
            ],
        );
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $csrf = $this->validCsrf();
        $request = $this->post([
            ['label' => 'About', 'url' => 'https://example.test/about'],
            ['label' => '   ', 'url' => ''],
            ['label' => 'Contact', 'url' => 'https://example.test/contact'],
            ['label' => '', 'url' => ''],
            ['label' => '', 'url' => ''],
        ]);

        $response = $this->controller($twig, $settings, $csrf)->settings($request, $this->publication($mount, $prefix));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame($prefix . '/settings', $response->getTargetUrl());
        self::assertSame(['unfold_admin.saved'], $request->getSession()->getFlashBag()->peek('unfold_success'));
    }

    /** @dataProvider mounts */
    public function testInvalidUrlKeepsSubmittedFields(PublicationMount $mount, string $prefix): void
    {
        $links = [['label' => 'About', 'url' => 'http://example.test/about']];
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::once())->method('savePresentation')->willReturnCallback(
            static function (string $coordinate, string $theme, array $footerLinks): void {
                new PublicationSettings($coordinate, $theme, $footerLinks);
            },
        );
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with(
            '@Unfold/admin/settings.html.twig',
            self::callback(static fn (array $context): bool => $context['footerLinks'] === $links
                && $context['selectedTheme'] === 'default'
                && $context['error'] === 'unfold_setup.invalid_footer_links'),
        )->willReturn('invalid settings');
        $csrf = $this->validCsrf();

        $response = $this->controller($twig, $settings, $csrf)->settings($this->post($links), $this->publication($mount, $prefix));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid settings', $response->getContent());
    }

    /** @dataProvider mounts */
    public function testInvalidCsrfRejectsBeforeSaving(PublicationMount $mount, string $prefix): void
    {
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('savePresentation');
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->expects(self::once())->method('isTokenValid')->with(
            self::callback(static fn (CsrfToken $token): bool => $token->getId() === 'unfold_settings:' . self::COORDINATE
                && $token->getValue() === 'valid'),
        )->willReturn(false);

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller($twig, $settings, $csrf)->settings(
            $this->post([['label' => 'About', 'url' => 'https://example.test/about']]),
            $this->publication($mount, $prefix),
        );
    }

    public function testResolvesNaddrBeforeSavingAboutArticle(): void
    {
        $coordinate = '30023:' . str_repeat('b', 64) . ':about';
        $naddr = (string) Bech32::naddr(kind: 30023, pubkey: str_repeat('b', 64), identifier: 'about', relays: ['wss://relay.example']);
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::once())->method('savePresentation')->with(
            self::COORDINATE, 'default', [], $coordinate, ['wss://relay.example'], true,
        );
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::once())->method('findByCoordinate')->with($coordinate, ['wss://relay.example'])
            ->willReturn(new NostrEvent(str_repeat('c', 64), str_repeat('b', 64), 30023, 'About', [['d', 'about']], 123, ''));
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::never())->method('render');
        $request = $this->post([]);
        $request->request->set('about_article', 'nostr:' . $naddr);

        $response = $this->controller($twig, $settings, $this->validCsrf(), $events)
            ->settings($request, $this->publication(PublicationMount::SUBDOMAIN, '/admin'));

        self::assertSame(303, $response->getStatusCode());
    }

    public function testMismatchedArticleKeepsSubmittedInputAndDoesNotSave(): void
    {
        $coordinate = '30023:' . str_repeat('b', 64) . ':about';
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('savePresentation');
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->with($coordinate)
            ->willReturn(new NostrEvent(str_repeat('c', 64), str_repeat('b', 64), 30023, 'About', [['d', 'other']], 123, ''));
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with(
            '@Unfold/admin/settings.html.twig',
            self::callback(static fn (array $context): bool => $context['aboutArticle'] === $coordinate
                && $context['error'] === 'unfold_setup.invalid_about_article'),
        )->willReturn('invalid article');
        $request = $this->post([]);
        $request->request->set('about_article', $coordinate);

        $response = $this->controller($twig, $settings, $this->validCsrf(), $events)
            ->settings($request, $this->publication(PublicationMount::COORDINATE, '/mag/edition/admin'));

        self::assertSame(422, $response->getStatusCode());
    }
    public function testSavingUnchangedAboutCoordinatePreservesRelayHints(): void
    {
        $coordinate = '30023:' . str_repeat('b', 64) . ':about';
        $hints = ['wss://relay.example'];
        $publication = new PublicationContext(
            self::COORDINATE,
            new PublicationSettings(self::COORDINATE, 'default', [], $coordinate, $hints),
            PublicationMount::SUBDOMAIN,
            '/admin',
        );
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::once())->method('savePresentation')->with(
            self::COORDINATE, 'default', [], $coordinate, $hints, true,
        );
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::once())->method('findByCoordinate')->with($coordinate, $hints)
            ->willReturn(new NostrEvent(str_repeat('c', 64), str_repeat('b', 64), 30023, 'About', [['d', 'about']], 123, ''));
        $request = $this->post([]);
        $request->request->set('about_article', $coordinate);
        $response = $this->controller($this->createMock(Environment::class), $settings, $this->validCsrf(), $events)
            ->settings($request, $publication);
        self::assertSame(303, $response->getStatusCode());
    }
    public static function mounts(): iterable
    {
        yield 'subdomain' => [PublicationMount::SUBDOMAIN, '/admin'];
        yield 'coordinate' => [PublicationMount::COORDINATE, '/mag/edition/admin'];
    }

    private function publication(PublicationMount $mount, string $prefix): PublicationContext
    {
        return new PublicationContext(
            self::COORDINATE,
            new PublicationSettings(self::COORDINATE),
            $mount,
            $prefix,
        );
    }

    /** @param list<array{label: string, url: string}> $links */
    private function post(array $links): Request
    {
        $request = Request::create('/settings', 'POST', [
            '_token' => 'valid',
            'theme' => 'default',
            'footer_links' => $links,
        ]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function validCsrf(): CsrfTokenManagerInterface
    {
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->expects(self::once())->method('isTokenValid')->with(
            self::callback(static fn (CsrfToken $token): bool => $token->getId() === 'unfold_settings:' . self::COORDINATE
                && $token->getValue() === 'valid'),
        )->willReturn(true);

        return $csrf;
    }

    private function controller(
        Environment $twig,
        PublicationSettingsManager $settings,
        CsrfTokenManagerInterface $csrf,
        ?EventReadGatewayInterface $events = null,
    ): PublicationAdminController {
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->method('getAvailableThemes')->willReturn(['default']);

        return new PublicationAdminController(
            $twig,
            $settings,
            $renderer,
            $events ?? $this->createMock(EventReadGatewayInterface::class),
            $csrf,
            $this->createMock(LoggerInterface::class),
        );
    }
}
