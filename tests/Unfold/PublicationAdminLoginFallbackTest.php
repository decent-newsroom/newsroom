<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Controller\LoginController;
use App\Entity\User;
use App\Unfold\PublicationAdminLogin;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class PublicationAdminLoginFallbackTest extends TestCase
{
    /** @dataProvider cookieScopes */
    public function testAuthenticatedLocalhostLoginTerminatesOnTheEquivalentMainDomainPage(?string $scope, string $page): void
    {
        $destination = 'https://soskewed.localhost:8443/admin' . $page;
        $request = $this->request('https://localhost:8443/login', $destination);
        $controller = $this->getMockBuilder(LoginController::class)->onlyMethods(['render'])->getMock();
        $controller->expects(self::never())->method('render');

        $response = $controller->index($this->owner(), $request, $this->login('localhost', $scope));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://localhost:8443/mag/daily/admin' . $page, $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        // Reloading the old bookmark must not restart cross-host redirects.
        self::assertSame($response->headers->get('Location'),
            $controller->index($this->owner(), $request, $this->login('localhost', $scope))->headers->get('Location'));
    }

    public static function cookieScopes(): iterable
    {
        yield 'empty scope overview' => [null, ''];
        yield 'empty scope settings' => ['', '/settings'];
        yield 'localhost cookie cannot be relied on' => ['.localhost', ''];
    }

    public function testHostOnlyProductionCookieUsesTheSameFallback(): void
    {
        $request = $this->request('https://example.test/login', 'https://soskewed.example.test/admin/settings');
        self::assertSame('https://example.test/mag/daily/admin/settings',
            $this->login('example.test', null)->resolveReturnUrl($request->query->get('unfold_return'), $request, $this->owner()));
    }

    public function testSharedCookieReturnIsTriedOnceBeforeFallback(): void
    {
        $destination = 'https://soskewed.example.test/admin/settings';
        $request = $this->request('https://example.test/login', $destination);
        $login = $this->login('example.test', '.example.test');

        self::assertSame($destination, $login->resolveReturnUrl($destination, $request, $this->owner()));
        self::assertSame('https://example.test/mag/daily/admin/settings',
            $login->resolveReturnUrl($destination, $request, $this->owner()));

        $request->getSession()->set('unfold_admin_return_attempt', ['url' => $destination, 'time' => time() - 61]);
        self::assertSame($destination, $login->resolveReturnUrl($destination, $request, $this->owner()));
    }

    public function testAnotherOwnerCannotFallBackToACollidingDtag(): void
    {
        $request = $this->request('https://localhost:8443/login', 'https://soskewed.localhost:8443/admin');
        $otherUser = new User();
        $otherUser->setNpub(PublicKey::fromHex(str_repeat('b', 64))->toBech32());
        $otherUser->setRoles(['ROLE_ADMIN']);
        $this->expectException(AccessDeniedHttpException::class);
        $this->login('localhost', null)->resolveReturnUrl($request->query->get('unfold_return'), $request, $otherUser);
    }

    public function testCoordinateContinuationDoesNotChange(): void
    {
        $url = 'https://localhost:8443/mag/daily/admin';
        $request = $this->request('https://localhost:8443/login', $url);
        self::assertSame($url, $this->login('localhost', null)->resolveReturnUrl($url, $request, $this->owner()));
    }

    private function request(string $loginUrl, string $destination): Request
    {
        $request = Request::create($loginUrl, 'GET', ['unfold_return' => $destination]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        return $request;
    }

    private function owner(): User
    {
        $user = new User();
        $user->setNpub(PublicKey::fromHex(str_repeat('a', 64))->toBech32());
        return $user;
    }

    private function login(string $baseDomain, ?string $cookieDomain): PublicationAdminLogin
    {
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->method('findBySubdomain')->willReturnCallback(static fn (string $subdomain) => $subdomain === 'soskewed'
            ? new PublicationSite('soskewed', '30040:' . str_repeat('a', 64) . ':daily') : null);
        return new PublicationAdminLogin($sites, $baseDomain, $cookieDomain);
    }
}
