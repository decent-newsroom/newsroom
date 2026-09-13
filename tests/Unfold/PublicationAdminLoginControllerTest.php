<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Controller\LoginController;
use App\Entity\User;
use App\Unfold\PublicationAdminLogin;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicationAdminLoginControllerTest extends TestCase
{
    /** @dataProvider safeDestinations */
    public function testAuthenticatedPageVisitResumesTrustedAdministration(string $destination): void
    {
        $controller = $this->getMockBuilder(LoginController::class)->onlyMethods(['render'])->getMock();
        $controller->expects(self::never())->method('render');
        $request = Request::create('https://example.test/login', 'GET', ['unfold_return' => $destination]);

        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $user = new User();
        $user->setNpub(\Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey::fromHex(str_repeat('a', 64))->toBech32());
        $response = $controller->index($user, $request, $this->login());

        self::assertSame(303, $response->getStatusCode());
        self::assertSame($destination, $response->headers->get('Location'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function safeDestinations(): iterable
    {
        yield 'main domain' => ['https://example.test/mag/daily/admin'];
        yield 'registered publication' => ['https://journal.example.test/admin/settings'];
    }

    /** @dataProvider absentOrUnsafeDestinations */
    public function testAuthenticatedPageWithoutSafeContinuationPreservesExistingRender(array $query): void
    {
        $rendered = new Response('authenticated login page');
        $controller = $this->getMockBuilder(LoginController::class)->onlyMethods(['render'])->getMock();
        $controller->expects(self::once())->method('render')
            ->with('login/index.html.twig', ['authenticated' => true])
            ->willReturn($rendered);

        self::assertSame($rendered, $controller->index(
            new User(),
            Request::create('https://example.test/login', 'GET', $query),
            $this->login(),
        ));
    }

    public static function absentOrUnsafeDestinations(): iterable
    {
        yield 'missing' => [[]];
        yield 'external' => [['unfold_return' => 'https://evil.test/admin']];
        yield 'unregistered publication' => [['unfold_return' => 'https://unknown.example.test/admin']];
        yield 'platform admin' => [['unfold_return' => 'https://example.test/admin']];
    }

    /** @dataProvider apiHeaders */
    public function testAuthenticatedApiRequestKeepsSuccessfulJsonEvenWithContinuation(array $headers): void
    {
        $controller = $this->getMockBuilder(LoginController::class)->onlyMethods(['render'])->getMock();
        $controller->expects(self::never())->method('render');
        $request = Request::create('https://example.test/login', 'POST', [
            'unfold_return' => 'https://journal.example.test/admin',
        ]);
        // The login continuation is carried in the URL while signer data is posted.
        $request->query->set('unfold_return', 'https://journal.example.test/admin');
        $request->headers->add($headers);

        $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
        $user = new User();
        $user->setNpub(\Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey::fromHex(str_repeat('a', 64))->toBech32());
        $response = $controller->index($user, $request, $this->login());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['message' => 'Authentication Successful'], json_decode($response->getContent(), true));
        self::assertFalse($response->headers->has('Location'));
    }

    public static function apiHeaders(): iterable
    {
        yield 'XHR signer request' => [['X-Requested-With' => 'XMLHttpRequest', 'Authorization' => 'Nostr test']];
        yield 'JSON signer request' => [['Accept' => 'application/json', 'Authorization' => 'Nostr test']];
    }

    public function testUnauthenticatedAuthorizationAttemptPreservesJsonFailure(): void
    {
        $controller = $this->getMockBuilder(LoginController::class)->onlyMethods(['render'])->getMock();
        $controller->expects(self::never())->method('render');
        $request = Request::create('https://example.test/login', 'POST');
        $request->headers->set('Authorization', 'Nostr invalid');
        $request->query->set('unfold_return', 'https://journal.example.test/admin');

        $response = $controller->index(null, $request, $this->login());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(['message' => 'Unauthenticated'], json_decode($response->getContent(), true));
        self::assertFalse($response->headers->has('Location'));
    }

    public function testAnonymousPageVisitRendersLoginBeforeContinuing(): void
    {
        $rendered = new Response('login page');
        $controller = $this->getMockBuilder(LoginController::class)->onlyMethods(['render'])->getMock();
        $controller->expects(self::once())->method('render')
            ->with('login/index.html.twig', ['authenticated' => false])
            ->willReturn($rendered);

        self::assertSame($rendered, $controller->index(
            null,
            Request::create('https://example.test/login', 'GET', ['unfold_return' => 'https://journal.example.test/admin']),
            $this->login(),
        ));
    }

    private function login(): PublicationAdminLogin
    {
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->method('findBySubdomain')->willReturnCallback(
            static fn (string $subdomain): ?PublicationSite => $subdomain === 'journal'
                ? new PublicationSite('journal', '30040:' . str_repeat('a', 64) . ':daily')
                : null,
        );

        return new PublicationAdminLogin($sites, 'example.test', '.example.test');
    }
}
