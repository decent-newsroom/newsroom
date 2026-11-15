<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\EventController;
use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\ArticleEventProjector;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\GenericEventProjector;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\UserRelayListService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class EventControllerWorkspacePageTest extends TestCase
{
    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const VIEWER = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @dataProvider exactIdentifiers */
    public function testLocalNoteUsesAuthorizedSnapshotAndPrivateResponse(string $identifier): void
    {
        $tags = [
            ['title', 'First'],
            ['e', str_repeat('d', 64), '', 'mention'],
            ['e', str_repeat('d', 64), 'wss://relay.example', 'mention'],
            ['t', 'news'],
            ['a', '30023:' . self::AUTHOR . ':article'],
        ];
        [$controller, $response] = $this->request($this->event(1, $tags), $this->policy(), identifier: $identifier);

        self::assertSame('event/index.html.twig', $controller->view);
        $snapshot = $controller->parameters['eventPresentation'];
        self::assertInstanceOf(EventPresentation::class, $snapshot);
        self::assertSame('Full body with https://example.test/', $snapshot->textContent);
        self::assertSame($tags, $snapshot->getRawTags());
        self::assertCount(3, $snapshot->references);
        self::assertSame([1, 2, 4], array_map(static fn ($ref) => $ref->position, $snapshot->references));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public static function exactIdentifiers(): iterable
    {
        yield 'note' => [(new NostrNip19Service())->encodeNote(self::ID)];
        yield 'nevent' => [(string) Bech32::nevent(id: self::ID, kind: 1)];
    }

    /** @dataProvider specialistAddresses */
    public function testSpecialistAddressesRedirectBeforeEventLookup(int $kind, string $route): void
    {
        $controller = new class($this->createMock(ArticleEventProjector::class), $this->createMock(ContentAuthorAccessPolicy::class)) extends EventController {
            public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/' . $route, $status);
            }
        };
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::never())->method('findByNaddr');
        $logger = new NullLogger();
        $response = $controller->index(
            (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'slug', $kind),
            new Request(), $this->createMock(RedisCacheService::class), new NostrLinkParser($logger),
            $logger, $repository, $this->createMock(MessageBusInterface::class),
            $this->createMock(NostrClient::class), $this->createMock(GenericEventProjector::class),
            $this->createMock(UserRelayListService::class),
        );
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/' . $route, $response->getTargetUrl());
    }

    public static function specialistAddresses(): iterable
    {
        yield 'longform' => [30023, 'author-article-slug'];
        yield 'chapter' => [30041, 'chapter'];
    }

    /** @dataProvider deniedPolicies */
    public function testLocalNoteDenialStopsBeforeMetadata(string $reason): void
    {
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMetadata');
        $this->expectException(NotFoundHttpException::class);
        $this->request($this->event(1, []), $this->policy($reason), $metadata);
    }

    public static function deniedPolicies(): iterable
    {
        yield 'tombstone' => ['deleted'];
        yield 'mute' => ['muted'];
        yield 'mute lookup failure' => ['failure'];
    }

    public function testMissingPreviewServicesDoNotFallBackToAnUncheckedPreview(): void
    {
        $controller = new EventController(
            $this->createMock(ArticleEventProjector::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findById')->willReturn($this->event(1, []));
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMetadata');
        $logger = new NullLogger();
        $this->expectException(\LogicException::class);
        $controller->index(
            (new NostrNip19Service())->encodeNote(self::ID), new Request(), $metadata,
            new NostrLinkParser($logger), $logger, $repository,
            $this->createMock(MessageBusInterface::class), $this->createMock(NostrClient::class),
            $this->createMock(GenericEventProjector::class), $this->createMock(UserRelayListService::class),
        );
    }

    public function testHighlightPagePreservesOnlyValidLowercaseNip84EventSourcesAndDisablesSharedCache(): void
    {
        $exactId = str_repeat('d', 64);
        [$controller, $response] = $this->request($this->event(KindsEnum::HIGHLIGHTS->value, [
            ['E', str_repeat('e', 64)],
            ['e', strtoupper($exactId), 'wss://relay.example', 'mention'],
            ['a', '30023:' . self::AUTHOR . ':article'],
        ]), $this->policy('deleted'));

        self::assertSame(strtolower($exactId), $controller->parameters['highlightEventRef']);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function testHighlightPageIgnoresUppercaseEventSourceTag(): void
    {
        [$controller, $response] = $this->request($this->event(KindsEnum::HIGHLIGHTS->value, [
            ['E', str_repeat('e', 64)],
        ]), $this->policy('deleted'));

        self::assertNull($controller->parameters['highlightEventRef']);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    /** @dataProvider legacyCases */
    public function testOtherKindsAndWarningOrScopeRemainOnExistingPath(int $kind, array $tags): void
    {
        [$controller, $response] = $this->request($this->event($kind, $tags), $this->policy('deleted'));
        self::assertNull($controller->parameters['eventPresentation']);
        self::assertSame($tags, $controller->parameters['event']->tags);
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
    }

    public static function legacyCases(): iterable
    {
        yield 'unknown scoped kind' => [65000, [['h', 'private-group']]];
        yield 'comment specialist' => [1111, [['A', 'scope']]];
        yield 'picture specialist' => [20, []];
        yield 'article fallback' => [30023, []];
        yield 'chapter fallback' => [30041, []];
        yield 'warning' => [1, [['content-warning', 'warning']]];
        yield 'sensitive label' => [1, [['t', 'nsfw']]];
        yield 'scoped note' => [1, [['h', 'private-group']]];
        yield 'community note' => [1, [['A', 'scope']]];
        yield 'legacy community coordinate' => [1, [['a', '34550:' . self::AUTHOR . ':community', 'wss://relay.example']]];
        yield 'legacy positional root' => [1, [['e', str_repeat('d', 64)]]];
        yield 'legacy positional root and reply' => [1, [['e', str_repeat('d', 64), 'wss://relay.example'], ['e', str_repeat('e', 64)]]];
        yield 'unmarked alongside mention' => [1, [['e', str_repeat('d', 64), '', 'mention'], ['e', str_repeat('e', 64)]]];
        yield 'marked reply only' => [1, [['e', str_repeat('d', 64), '', 'reply']]];
        yield 'conflicting markers' => [1, [['e', str_repeat('d', 64), 'reply', 'mention']]];
        yield 'malformed e tag' => [1, [['e']]];
        yield 'malformed a tag' => [1, [['a', null]]];
        yield 'malformed a coordinate string' => [1, [['a', 'not-a-coordinate']]];
        yield 'malformed a coordinate pubkey' => [1, [['a', '30040:not-a-pubkey:identifier']]];
        // A self-root is ignored by the existing OP resolver.
        yield 'threaded note' => [1, [['e', self::ID, '', 'root']]];
    }

    private function event(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setContent('Full body with https://example.test/');
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setSig(str_repeat('f', 128));
        return $event;
    }

    private function request(Event $event, EventPreviewAccessPolicy $policy, ?RedisCacheService $metadata = null, ?string $identifier = null): array
    {
        $controller = new class($this->createMock(ArticleEventProjector::class), $this->createMock(ContentAuthorAccessPolicy::class)) extends EventController {
            public string $view = '';
            public array $parameters = [];
            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->view = $view;
                $this->parameters = $parameters;
                return $response ?? new Response('rendered');
            }
        };
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findById')->with(self::ID)->willReturn($event);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventById');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = new NullLogger();
        $response = $controller->index(
            $identifier ?? (new NostrNip19Service())->encodeNote(self::ID), new Request(),
            $metadata ?? $this->createMock(RedisCacheService::class),
            new NostrLinkParser($logger), $logger, $repository, $bus, $client,
            $this->createMock(GenericEventProjector::class),
            $this->createMock(UserRelayListService::class), $policy,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
        );
        return [$controller, $response];
    }

    private function policy(string $reason = ''): EventPreviewAccessPolicy
    {
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn($reason === 'deleted');
        $security = $this->createMock(Security::class);
        $mutes = $this->createMock(UserMuteListService::class);
        if (in_array($reason, ['muted', 'failure'], true)) {
            $user = $this->createMock(UserInterface::class);
            $user->method('getUserIdentifier')->willReturn(self::VIEWER);
            $security->method('getUser')->willReturn($user);
            if ($reason === 'failure') {
                $mutes->method('getMutedPubkeysStrict')->willThrowException(new \RuntimeException('unavailable'));
            } else {
                $mutes->method('getMutedPubkeysStrict')->willReturn([self::AUTHOR]);
            }
        }

        return new EventPreviewAccessPolicy(
            $this->createMock(ContentAuthorAccessPolicy::class),
            $deletions,
            $this->createMock(HiddenCoordinateRepository::class),
            new RecordIdentityService(),
            $security,
            $mutes,
            new NostrKeyService(),
            new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
    }
}
