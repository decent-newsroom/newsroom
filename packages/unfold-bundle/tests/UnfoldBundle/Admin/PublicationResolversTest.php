<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Admin\CoordinatePublicationResolver;
use DecentNewsroom\UnfoldBundle\Admin\HostPublicationResolver;
use DecentNewsroom\UnfoldBundle\Admin\PublicationMount;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class PublicationResolversTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testOwnerGetsEquivalentSettingsFromBothMounts(): void
    {
        $coordinate = '30040:' . self::OWNER . ':edition:2026';
        $saved = new PublicationSettings($coordinate, 'editorial');
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::exactly(2))->method('get')->with($coordinate)->willReturn($saved);
        $site = new PublicationSite('edition', $coordinate);
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::once())->method('findByCoordinate')->with($coordinate)->willReturn($this->event(dtag: 'edition:2026'));
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->expects(self::once())->method('findByCoordinate')->with($coordinate)->willReturn($site);

        $hostRequest = Request::create('https://edition.example.test:8443/admin');
        $hostRequest->attributes->set('_unfold_site', $site);
        $host = (new HostPublicationResolver($settings))->resolve($hostRequest, self::OWNER);
        $coordinateRequest = Request::create('https://example.test:8443/mag/edition%3A2026/admin');
        $coordinateRequest->attributes->set('mag', 'edition:2026');
        $main = (new CoordinatePublicationResolver($settings, $events, $sites))->resolve($coordinateRequest, self::OWNER);

        self::assertSame($host->coordinate, $main->coordinate);
        self::assertSame($saved, $host->settings);
        self::assertSame($saved, $main->settings);
        self::assertSame(self::OWNER, $main->ownerPubkey);
        self::assertSame('edition:2026', $main->dtag);
        self::assertSame(PublicationMount::SUBDOMAIN, $host->mount);
        self::assertSame(PublicationMount::COORDINATE, $main->mount);
        self::assertSame('/admin', $host->adminPathPrefix);
        self::assertSame('/mag/edition%3A2026/admin', $main->adminPathPrefix);
        self::assertSame('https://edition.example.test:8443', $host->publicUrl);
        self::assertSame($host->publicUrl, $main->publicUrl);
        self::assertSame($site, $main->site);
    }

    public function testHostRejectsNonOwnerBeforeReadingSettings(): void
    {
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $request = Request::create('https://edition.example.test/admin');
        $request->attributes->set('_unfold_site', new PublicationSite('edition', '30040:' . self::OWNER . ':edition'));

        $this->expectException(AccessDeniedHttpException::class);
        (new HostPublicationResolver($settings))->resolve($request, self::OTHER);
    }

    /** @dataProvider malformedHosting */
    public function testMalformedHostingReturnsUnavailableWithoutReadingSettings(?PublicationSite $site): void
    {
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $request = Request::create('https://edition.example.test/admin');
        $request->attributes->set('_unfold_site', $site);

        $this->expectException(ServiceUnavailableHttpException::class);
        (new HostPublicationResolver($settings))->resolve($request, self::OWNER);
    }

    public static function malformedHosting(): iterable
    {
        yield 'missing mapping' => [null];
        yield 'invalid owner' => [new PublicationSite('edition', '30040:invalid:edition')];
        yield 'wrong kind' => [new PublicationSite('edition', '30023:' . self::OWNER . ':edition')];
        yield 'missing d-tag' => [new PublicationSite('edition', '30040:' . self::OWNER . ':')];
    }

    public function testUnhostedPublicationUsesDefaultSettingsAndHasNoPublicLinks(): void
    {
        $coordinate = '30040:' . self::OWNER . ':edition';
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::once())->method('find')->with($coordinate)->willReturn(null);
        $settings = new PublicationSettingsManager(
            $store,
            $this->createMock(HandlebarsRenderer::class),
            $this->createMock(SiteConfigLoader::class),
        );
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::once())->method('findByCoordinate')->with($coordinate)->willReturn($this->event());
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->expects(self::once())->method('findByCoordinate')->with($coordinate)->willReturn(null);
        $request = Request::create('https://example.test/mag/edition/admin');
        $request->attributes->set('mag', 'edition');

        $context = (new CoordinatePublicationResolver($settings, $events, $sites))->resolve($request, self::OWNER);

        self::assertSame('default', $context->settings->theme);
        self::assertSame($coordinate, $context->settings->coordinate);
        self::assertNull($context->site);
        self::assertNull($context->publicUrl);
    }

    /** @dataProvider missingOrMismatchedRoots */
    public function testExactOwnerCoordinateRejectsMissingOrMismatchedEvents(?NostrEvent $event): void
    {
        $coordinate = '30040:' . self::OWNER . ':edition';
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::once())->method('findByCoordinate')->with($coordinate)->willReturn($event);
        $events->expects(self::never())->method('findByCoordinates');
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->expects(self::never())->method('findByCoordinate');
        $request = Request::create('https://example.test/mag/edition/admin');
        $request->attributes->set('mag', 'edition');

        $this->expectException(NotFoundHttpException::class);
        (new CoordinatePublicationResolver($settings, $events, $sites))->resolve($request, self::OWNER);
    }

    public static function missingOrMismatchedRoots(): iterable
    {
        yield 'missing root' => [null];
        yield 'same d-tag from another owner' => [new NostrEvent('id', self::OTHER, 30040, '', [['d', 'edition']], 0, '')];
        yield 'wrong event kind' => [new NostrEvent('id', self::OWNER, 30023, '', [['d', 'edition']], 0, '')];
        yield 'wrong d-tag' => [new NostrEvent('id', self::OWNER, 30040, '', [['d', 'other']], 0, '')];
        yield 'no d-tag' => [new NostrEvent('id', self::OWNER, 30040, '', [], 0, '')];
    }

    public function testGatewayFailureIsUnavailableInsteadOfNotFound(): void
    {
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->willThrowException(new \RuntimeException('Relay unavailable'));
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->expects(self::never())->method('findByCoordinate');
        $request = Request::create('https://example.test/mag/edition/admin');
        $request->attributes->set('mag', 'edition');

        $this->expectException(ServiceUnavailableHttpException::class);
        (new CoordinatePublicationResolver($settings, $events, $sites))->resolve($request, self::OWNER);
    }

    public function testEmptyDtagDoesNotQueryGateway(): void
    {
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::never())->method('findByCoordinate');

        $this->expectException(NotFoundHttpException::class);
        (new CoordinatePublicationResolver(
            $this->createMock(PublicationSettingsManager::class),
            $events,
            $this->createMock(SiteRegistryInterface::class),
        ))->resolve(Request::create('https://example.test/mag//admin'), self::OWNER);
    }

    private function event(string $dtag = 'edition'): NostrEvent
    {
        return new NostrEvent('id', self::OWNER, 30040, '', [['d', $dtag]], 0, '');
    }
}
