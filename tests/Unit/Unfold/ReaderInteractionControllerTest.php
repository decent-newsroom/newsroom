<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionDelivery;
use DecentNewsroom\UnfoldBundle\Contract\InteractionPage;
use DecentNewsroom\UnfoldBundle\Contract\InteractionReaderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionState;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\ReaderIdentityInterface;
use DecentNewsroom\UnfoldBundle\Contract\ReaderWriteLimiterInterface;
use DecentNewsroom\UnfoldBundle\Contract\SignedInteractionPublisherInterface;
use DecentNewsroom\UnfoldBundle\Controller\ReaderInteractionController;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionPolicy;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionView;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Translator;

final class ReaderInteractionControllerTest extends TestCase
{
    private InteractionTarget $target;
    private InteractionReaderInterface $reader;
    private SignedInteractionPublisherInterface $publisher;
    private ?string $pubkey;
    private ReaderInteractionController $controller;
    private bool $failReads = false;

    protected function setUp(): void
    {
        $this->pubkey = str_repeat('c', 64);
        $event = new NostrEvent(str_repeat('b', 64), str_repeat('a', 64), 30817, 'Content', [['d', 'Spec:with/slash ']], time(), str_repeat('f', 128));
        $this->target = new InteractionTarget('30040:' . str_repeat('a', 64) . ':root', PostData::fromEvent($event), $event, 'wss://relay.example');
        $this->reader = $this->createMock(InteractionReaderInterface::class);
        $this->reader->method('target')->willReturn($this->target);
        $this->reader->method('state')->willReturn(new InteractionState(false, false, 0, 0));
        $this->reader->method('thread')->willReturnCallback(function (): InteractionPage {
            if ($this->failReads) {
                throw new \RuntimeException('Local discussion unavailable');
            }
            return new InteractionPage([], null, 0);
        });
        $this->publisher = $this->createMock(SignedInteractionPublisherInterface::class);
        $identity = $this->createMock(ReaderIdentityInterface::class);
        $identity->method('pubkey')->willReturnCallback(fn(): ?string => $this->pubkey);
        $identity->method('loginUrl')->willReturn('https://publication.localhost/login');
        $limiter = $this->createMock(ReaderWriteLimiterInterface::class);
        $limiter->method('consume')->willReturn(true);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('reader', 'valid'));
        $csrf->method('isTokenValid')->willReturnCallback(static fn(CsrfToken $token): bool => $token->getValue() === 'valid');
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMultipleMetadata')->willReturn([]);
        $context = new ContextBuilder($this->createMock(MarkdownConverterInterface::class), new ArrayAdapter(), $profiles, $this->createMock(CommentProviderInterface::class));
        $this->controller = new ReaderInteractionController($this->reader, $this->publisher, $identity, $limiter, new InteractionPolicy(), new InteractionView($profiles, $context), $csrf, new Translator('en'), new NullLogger());
    }

    public function testAnonymousPublicReadHasNoOwnStateAndQueuesRefresh(): void
    {
        $this->pubkey = null;
        $this->reader->expects(self::once())->method('refresh')->with($this->target);
        $response = $this->controller->index($this->request());
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertSame([], $data['comments']);
        self::assertArrayNotHasKey('pubkey', $data);
        self::assertArrayNotHasKey('csrf_token', $data);
    }

    public function testOwnStateIsNeverCacheable(): void
    {
        $response = $this->controller->me($this->request());
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame($this->pubkey, json_decode($response->getContent(), true)['pubkey']);
    }

    public function testAnonymousWriteIsRejectedBeforePublisher(): void
    {
        $this->pubkey = null;
        $this->publisher->expects(self::never())->method('publish');
        self::assertSame(401, $this->controller->prepare($this->request('POST', ['action' => 'comment', 'content' => 'Hello']))->getStatusCode());
    }

    public function testBadCsrfAndForeignOriginAreRejected(): void
    {
        self::assertSame(403, $this->controller->prepare($this->request('POST', ['action' => 'like', '_token' => 'bad']))->getStatusCode());
        $request = $this->request('POST', ['action' => 'like']);
        $request->headers->set('Origin', 'https://foreign.example');
        self::assertSame(403, $this->controller->prepare($request)->getStatusCode());
    }

    public function testLocalAcceptanceIsQueuedNotPublished(): void
    {
        $event = (new InteractionPolicy())->prepare($this->target, $this->pubkey, 'like');
        $event = [...$event, 'id' => str_repeat('d', 64), 'sig' => str_repeat('e', 128)];
        $this->publisher->expects(self::once())->method('publish')->with($event, $this->target, $this->pubkey)
            ->willReturn(new InteractionDelivery($event['id'], 'queued', true));
        $response = $this->controller->publish($this->request('POST', ['action' => 'like', 'event' => $event]));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertTrue($data['local_commit']);
        self::assertFalse($data['published']);
        self::assertSame('queued', $data['status']);
    }

    public function testAlteredSignedTargetNeverReachesPublisher(): void
    {
        $event = (new InteractionPolicy())->prepare($this->target, $this->pubkey, 'like');
        $event['tags'][0][1] = str_repeat('0', 64);
        $event = [...$event, 'id' => str_repeat('d', 64), 'sig' => str_repeat('e', 128)];
        $this->publisher->expects(self::never())->method('publish');
        self::assertSame(409, $this->controller->publish($this->request('POST', ['action' => 'like', 'event' => $event]))->getStatusCode());
    }

    public function testForeignParentCannotBeRepliedTo(): void
    {
        $this->reader->method('parent')->willReturn(null);
        $this->publisher->expects(self::never())->method('publish');
        self::assertSame(422, $this->controller->prepare($this->request('POST', ['action' => 'reply', 'parent_id' => str_repeat('d', 64), 'content' => 'Hello']))->getStatusCode());
    }

    public function testUnavailableReadIsNotSuccessfulEmptyDiscussion(): void
    {
        $this->failReads = true;
        self::assertSame(503, $this->controller->index($this->request())->getStatusCode());
    }

    public function testRefreshFailurePreservesLocalDiscussionWithExplicitStatus(): void
    {
        $this->reader->method('refresh')->willThrowException(new \RuntimeException('Queue unavailable'));
        $response = $this->controller->index($this->request());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('unavailable', json_decode($response->getContent(), true)['refresh_status']);
    }

    public function testOversizedRequestIsRejectedBeforeJsonParsing(): void
    {
        $request = Request::create('https://publication.localhost/unfold/api/interactions/prepare', 'POST', [], [], [], [], str_repeat(' ', 1_000_001));
        self::assertSame(413, $this->controller->prepare($request)->getStatusCode());
    }

    /** @param array<string, mixed> $data */
    private function request(string $method = 'GET', array $data = []): Request
    {
        $data = ['coordinate' => $this->target->post->coordinate, '_token' => 'valid', ...$data];
        $request = $method === 'GET'
            ? Request::create('https://publication.localhost/unfold/api/interactions?' . http_build_query($data))
            : Request::create('https://publication.localhost/unfold/api/interactions/prepare', $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
        $request->attributes->set('_unfold_site', new PublicationSite('publication', $this->target->publicationCoordinate));
        return $request;
    }
}
