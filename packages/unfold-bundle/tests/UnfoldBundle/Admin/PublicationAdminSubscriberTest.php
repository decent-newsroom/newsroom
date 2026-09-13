<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Admin\CoordinatePublicationResolver;
use DecentNewsroom\UnfoldBundle\Admin\HostPublicationResolver;
use DecentNewsroom\UnfoldBundle\Admin\PublicationAdminSubscriber;
use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Admin\PublicationMount;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationAdminIdentityInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PublicationAdminSubscriberTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testAnonymousAdminRedirectsBeforeAnyPublicationLookup(): void
    {
        $request = $this->hostRequest(self::OWNER, 'edition');
        $identity = $this->createMock(PublicationAdminIdentityInterface::class);
        $identity->expects(self::once())->method('pubkey')->willReturn(null);
        $identity->expects(self::once())->method('loginUrl')->with($request)->willReturn('https://example.test/login?continue=edition');
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::never())->method('findByCoordinate');
        $subscriber = $this->subscriber($identity, $settings, $events);
        $event = $this->controllerEvent($request);

        $subscriber->resolve($event);
        $response = ($event->getController())();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('https://example.test/login?continue=edition', $response->getTargetUrl());
        self::assertFalse($request->attributes->has('publication'));
        $subscriber->makePrivate($this->responseEvent($request, $response));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testConsecutiveRequestsKeepEachPublicationContextOnItsOwnRequest(): void
    {
        $identity = $this->createMock(PublicationAdminIdentityInterface::class);
        $identity->expects(self::exactly(2))->method('pubkey')->willReturnOnConsecutiveCalls(self::OWNER, self::OTHER);
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::exactly(2))->method('get')->willReturnCallback(
            static fn (string $coordinate): PublicationSettings => new PublicationSettings($coordinate),
        );
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::never())->method('findByCoordinate');
        $subscriber = $this->subscriber($identity, $settings, $events);
        $first = $this->hostRequest(self::OWNER, 'edition');
        $second = $this->hostRequest(self::OTHER, 'another');

        $subscriber->resolve($this->controllerEvent($first));
        $firstContext = $first->attributes->get('publication');
        $subscriber->resolve($this->controllerEvent($second));
        $secondContext = $second->attributes->get('publication');

        self::assertInstanceOf(PublicationContext::class, $firstContext);
        self::assertInstanceOf(PublicationContext::class, $secondContext);
        self::assertNotSame($firstContext, $secondContext);
        self::assertSame($firstContext, $first->attributes->get('publication'));
        self::assertSame(self::OWNER, $firstContext->ownerPubkey);
        self::assertSame('edition', $firstContext->dtag);
        self::assertSame('https://edition.example.test', $firstContext->publicUrl);
        self::assertSame(self::OTHER, $secondContext->ownerPubkey);
        self::assertSame('another', $secondContext->dtag);
        self::assertSame('https://another.example.test', $secondContext->publicUrl);
    }

    public function testCoordinateMountUsesAuthenticatedIdentity(): void
    {
        $coordinate = '30040:' . self::OWNER . ':edition';
        $identity = $this->createMock(PublicationAdminIdentityInterface::class);
        $identity->method('pubkey')->willReturn(self::OWNER);
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->method('get')->with($coordinate)->willReturn(new PublicationSettings($coordinate));
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::once())->method('findByCoordinate')->with($coordinate)->willReturn(
            new NostrEvent('id', self::OWNER, 30040, '', [['d', 'edition']], 0, ''),
        );
        $request = Request::create('https://example.test/mag/edition/admin');
        $request->attributes->add([
            '_unfold_admin' => true,
            '_unfold_mount' => PublicationMount::COORDINATE->value,
            'mag' => 'edition',
        ]);

        $this->subscriber($identity, $settings, $events)->resolve($this->controllerEvent($request));

        self::assertSame($coordinate, $request->attributes->get('publication')->coordinate);
        self::assertSame(PublicationMount::COORDINATE, $request->attributes->get('publication')->mount);
    }

    public function testUnmarkedRequestsAreUntouched(): void
    {
        $identity = $this->createMock(PublicationAdminIdentityInterface::class);
        $identity->expects(self::never())->method('pubkey');
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $request = Request::create('https://example.test/admin');
        $event = $this->controllerEvent($request);
        $originalController = $event->getController();
        $subscriber = $this->subscriber($identity, $settings, $this->createMock(EventReadGatewayInterface::class));
        $response = new Response();
        $response->setPublic()->setMaxAge(300);
        $cacheControl = $response->headers->get('Cache-Control');

        $subscriber->resolve($event);
        $subscriber->makePrivate($this->responseEvent($request, $response));

        self::assertSame($originalController, $event->getController());
        self::assertFalse($request->attributes->has('publication'));
        self::assertSame($cacheControl, $response->headers->get('Cache-Control'));
    }

    /** @dataProvider privateResponseStatuses */
    public function testAllAdminResponsesArePrivateAndNotStored(int $status): void
    {
        $subscriber = $this->subscriber(
            $this->createMock(PublicationAdminIdentityInterface::class),
            $this->createMock(PublicationSettingsManager::class),
            $this->createMock(EventReadGatewayInterface::class),
        );
        $request = $this->hostRequest(self::OWNER, 'edition');
        $response = new Response('', $status);
        $response->setPublic()->setMaxAge(600);

        $subscriber->makePrivate($this->responseEvent($request, $response));

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertFalse($response->headers->hasCacheControlDirective('max-age'));
    }

    public static function privateResponseStatuses(): iterable
    {
        foreach ([200, 303, 403, 404, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    private function subscriber(
        PublicationAdminIdentityInterface $identity,
        PublicationSettingsManager $settings,
        EventReadGatewayInterface $events,
    ): PublicationAdminSubscriber {
        return new PublicationAdminSubscriber(
            $identity,
            new HostPublicationResolver($settings),
            new CoordinatePublicationResolver($settings, $events, $this->createMock(SiteRegistryInterface::class)),
        );
    }

    private function hostRequest(string $owner, string $dtag): Request
    {
        $request = Request::create('https://' . $dtag . '.example.test/admin');
        $request->attributes->add([
            '_unfold_admin' => true,
            '_unfold_mount' => PublicationMount::SUBDOMAIN->value,
            '_unfold_site' => new PublicationSite($dtag, '30040:' . $owner . ':' . $dtag),
        ]);

        return $request;
    }

    private function controllerEvent(Request $request): ControllerEvent
    {
        return new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static fn (): Response => new Response(),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
