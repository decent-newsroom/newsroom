<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Unfold\ReaderLogin;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReaderLoginTest extends TestCase
{
    private function login(?string $cookieDomain = null): ReaderLogin
    {
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->method('findBySubdomain')->willReturnCallback(static fn(string $name): ?PublicationSite => $name === 'publication'
            ? new PublicationSite('publication', '30040:' . str_repeat('a', 64) . ':root') : null);
        return new ReaderLogin($sites, 'example.com', $cookieDomain);
    }

    private function path(): string
    {
        return '/' . Bech32::npub(str_repeat('b', 64)) . '/spec/' . rawurlencode(' NIP:with/slash ');
    }

    public function testSharedSessionLoginUsesMainDomainAndPublicReturn(): void
    {
        $request = Request::create('https://publication.example.com' . $this->path());
        $url = $this->login('.example.com')->loginUrl($request, $this->path());
        self::assertStringStartsWith('https://example.com/login?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('https://publication.example.com' . $this->path(), $query['unfold_reader_return']);
    }

    public function testHostOnlyCookiesAuthenticateOnPublicationHost(): void
    {
        $request = Request::create('https://publication.example.com' . $this->path());
        self::assertStringStartsWith('https://publication.example.com/login?', $this->login()->loginUrl($request, $this->path()));
    }

    public function testReturnsNeedNeitherOwnerNorAdminPath(): void
    {
        $request = Request::create('https://example.com/login');
        $url = 'https://publication.example.com' . $this->path();
        self::assertSame($url, $this->login()->validateReturnUrl($url, $request));
    }

    public function testForeignHostsAndAdminOrProtocolRelativeReturnsAreRejected(): void
    {
        $request = Request::create('https://example.com/login');
        foreach ([
            'https://evil.example.com' . $this->path(),
            'https://publication.example.com/admin',
            'https://publication.example.com' . $this->path() . '?redirect=https://evil.test',
            'https://publication.example.com' . $this->path() . '#fragment',
            'https://evil.test/' . $this->path(),
            '//publication.example.com' . $this->path(),
            'http://publication.example.com' . $this->path(),
            'https://user@publication.example.com' . $this->path(),
        ] as $url) {
            self::assertNull($this->login()->validateReturnUrl($url, $request), $url);
        }
    }
}
