<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Unfold\PublicationAdminLogin;
use App\Unfold\PublicationAdminRequestMatcher;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicationAdminLoginTest extends TestCase
{
    /** @dataProvider validReturnUrls */
    public function testOnlyRecognizedAdminDestinationsAreAccepted(string $url): void
    {
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->method('findBySubdomain')->willReturnCallback(
            static fn (string $subdomain): ?PublicationSite => $subdomain === 'journal'
                ? new PublicationSite('journal', '30040:' . str_repeat('a', 64) . ':daily')
                : null,
        );

        self::assertSame($url, (new PublicationAdminLogin($sites, 'example.test'))
            ->validateReturnUrl($url, Request::create('https://example.test/login')));
    }

    public static function validReturnUrls(): iterable
    {
        yield 'coordinate overview' => ['https://example.test/mag/daily/admin'];
        yield 'coordinate settings' => ['https://example.test/mag/daily/admin/settings'];
        yield 'encoded d-tag' => ['https://example.test/mag/daily%3Aedition/admin'];
        yield 'host overview' => ['https://journal.example.test/admin'];
        yield 'host settings' => ['https://journal.example.test/admin/settings'];
        yield 'explicit HTTPS port' => ['https://journal.example.test:443/admin'];
    }

    /** @dataProvider invalidReturnUrls */
    public function testUnsafeOrUnrecognizedDestinationsAreRejected(string $url): void
    {
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->method('findBySubdomain')->willReturn(null);

        self::assertNull((new PublicationAdminLogin($sites, 'example.test'))
            ->validateReturnUrl($url, Request::create('https://example.test/login')));
    }

    public static function invalidReturnUrls(): iterable
    {
        foreach ([
            'https://evil.test/admin',
            'https://example.test.evil.test/admin',
            'https://unknown.example.test/admin',
            'https://nested.journal.example.test/admin',
            '//example.test/mag/daily/admin',
            '/mag/daily/admin',
            'https://user@example.test/mag/daily/admin',
            'https://user:secret@example.test/mag/daily/admin',
            'https://example.test/mag/daily/admin?next=evil',
            'https://example.test/mag/daily/admin#section',
            'http://example.test/mag/daily/admin',
            'https://example.test:8443/mag/daily/admin',
            'https://example.test/admin',
            'https://example.test/mag/daily/admin/delete',
            'https://example.test/mag/daily/admin/',
            'https://example.test/mag/daily%2fother/admin',
            'https://example.test/mag/daily%5cother/admin',
            'https://example.test/mag/daily%00/admin',
            'https://example.test/mag/daily%20edition/admin',
            'https://example.test/mag//admin',
            'https://example.test/mag/../admin',
            'https://example.test/mag/%2e%2e/admin',
            'https://example.test/mag/./admin',
            'https://example.test/mag/daily/admin' . "\n",
            'https://example.test\@evil.test/mag/daily/admin',
        ] as $url) {
            yield $url => [$url];
        }
    }

    public function testLoginContinuationPreservesMountAndDevelopmentPortButDropsQuery(): void
    {
        $login = new PublicationAdminLogin($this->createMock(SiteRegistryInterface::class), 'example.test');
        $url = $login->loginUrl(Request::create('https://journal.example.test:8443/admin/settings?tracking=ignored'));
        $parts = parse_url($url);
        self::assertSame('https', $parts['scheme']);
        self::assertSame('example.test', $parts['host']);
        self::assertSame(8443, $parts['port']);
        self::assertSame('/login', $parts['path']);
        parse_str($parts['query'], $query);
        self::assertSame(['unfold_return' => 'https://journal.example.test:8443/admin/settings'], $query);

        self::assertSame('https://example.test:8443/mag/daily/admin', $login->validateReturnUrl(
            'https://example.test:8443/mag/daily/admin',
            Request::create('https://example.test:8443/login'),
        ));
    }

    /** @dataProvider routeMarkers */
    public function testPlatformRoleExemptionRequiresBothMarkerAndRecognizedRoute(?string $route, bool $marked, bool $expected): void
    {
        $request = Request::create('https://example.test/admin');
        $request->attributes->set('_route', $route);
        $request->attributes->set('_unfold_admin', $marked);

        self::assertSame($expected, (new PublicationAdminRequestMatcher())->matches($request));
    }

    public static function routeMarkers(): iterable
    {
        foreach (['unfold_admin_host_overview', 'unfold_admin_host_settings', 'unfold_admin_coordinate_overview', 'unfold_admin_coordinate_settings'] as $route) {
            yield $route => [$route, true, true];
            yield $route . ' unmarked' => [$route, false, false];
        }
        yield 'platform route with marker' => ['admin_dashboard', true, false];
        yield 'no route with marker' => [null, true, false];
        yield 'ordinary platform route' => ['admin_dashboard', false, false];
    }
}
