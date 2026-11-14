<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\User;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocDiscovery;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocSourceAccess;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\RelayRegistry;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final class PublicationTocDiscoveryControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SOURCE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CHILD = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const AUTHOR = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    /** @var list<FetchEventFromRelaysMessage> */
    private array $queued = [];
    private ?array $rendered = null;

    public function testPostDerivesOnlyRawTagPositionAndIgnoresBrowserIdentity(): void
    {
        $source = $this->event(self::SOURCE, self::OWNER, [
            ['d', 'book'], ['e', self::CHILD],
            ['a', '30041:' . self::AUTHOR . ':section:one', 'wss://evil.example'],
            ['a', '30041:' . self::AUTHOR . ':section:one'],
        ]);
        $response = $this->invoke([$source], '2', 'POST', [
            'coordinate' => '30024:' . self::OWNER . ':private',
            'target' => self::SOURCE, 'relayHints' => ['http://internal.example'],
        ]);
        self::assertSame('loading', json_decode($response->getContent(), true)['status']);
        self::assertCount(1, $this->queued);
        self::assertSame(30041, $this->queued[0]->kind);
        self::assertSame(self::AUTHOR, $this->queued[0]->pubkey);
        self::assertSame('section:one', $this->queued[0]->identifier);
        self::assertNull($this->queued[0]->exactVersionEventId);
        self::assertSame(['wss://configured.example'], $this->queued[0]->relays);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertNull($this->rendered);
    }

    public function testColdExactPinIsReportedUnavailableWithoutQueueing(): void
    {
        $source = $this->event(self::SOURCE, self::OWNER, [
            ['d', 'book'],
            ['a', '30041:' . self::AUTHOR . ':section:one', '', self::CHILD],
        ]);
        $response = $this->invoke([$source], '1', 'POST');

        self::assertSame('unavailable', json_decode($response->getContent(), true)['status']);
        self::assertSame([], $this->queued);
        self::assertNull($this->rendered);
    }

    public function testPollCannotDispatch(): void
    {
        $source = $this->event(self::SOURCE, self::OWNER, [['d', 'book'], ['a', '30040:' . self::AUTHOR . ':missing']]);
        $response = $this->invoke([$source], '1', 'GET');
        self::assertSame('loading', json_decode($response->getContent(), true)['status']);
        self::assertSame([], $this->queued);
    }

    /** @dataProvider rejectedTags */
    public function testUnsupportedOrInvalidReferenceNeverQueues(array $tag): void
    {
        $source = $this->event(self::SOURCE, self::OWNER, [['d', 'book'], $tag]);
        $this->expectException(NotFoundHttpException::class);
        try {
            $this->invoke([$source], '1', 'POST');
        } finally {
            self::assertSame([], $this->queued);
        }
    }

    public static function rejectedTags(): iterable
    {
        yield 'event id' => [['e', self::CHILD]];
        yield 'private draft' => [['a', '30024:' . self::AUTHOR . ':private']];
        yield 'article entitlement unsupported' => [['a', '30023:' . self::AUTHOR . ':article']];
        yield 'media' => [['a', '30078:' . self::AUTHOR . ':media']];
        yield 'malformed address' => [['a', 'invalid']];
        yield 'malformed pin' => [['a', '30040:' . self::AUTHOR . ':index', '', 'bad-pin']];
        yield 'non-string pin' => [['a', '30040:' . self::AUTHOR . ':index', '', ['pin']]];
    }

    public function testAnotherOwnersRootCannotQueue(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->invoke([$this->event(self::SOURCE, self::AUTHOR, [['d', 'book'], ['a', '30040:' . self::AUTHOR . ':index']])], '1', 'POST');
    }

    public function testInvalidCsrfCannotReadSourceOrQueue(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->invoke([], '1', 'POST', csrfValid: false);
    }

    public function testNestedPollRechecksOwnedRootAndExactSource(): void
    {
        $root = $this->event(self::SOURCE, self::OWNER, [['d', 'book'], ['a', '30040:' . self::AUTHOR . ':index', '', self::CHILD]]);
        $child = $this->event(self::CHILD, self::AUTHOR, [['d', 'index'], ['a', '30041:' . self::AUTHOR . ':section']]);
        $occurrence = str_repeat('f', 64);
        $query = [
            'path' => self::SOURCE, 'occurrence' => $occurrence,
            'signature' => (new PublicationTocTraversalSigner('secret'))->sign(
                self::CHILD, 1, [self::SOURCE], $occurrence, '30040:' . self::AUTHOR . ':index',
            ),
        ];
        $response = $this->invoke([$root, $child], '1', 'GET', $query, sourceId: self::CHILD);
        self::assertSame(200, $response->getStatusCode());
        // A valid signature does not preserve access after the owner disappears.
        $this->expectException(NotFoundHttpException::class);
        $this->invoke([$child], '1', 'GET', $query, sourceId: self::CHILD);
    }

    /** @dataProvider blockedPolicies */
    public function testEveryPollAndActionRechecksSourceAndTargetPolicy(string $policy, string $method): void
    {
        $source = $this->event(self::SOURCE, self::OWNER, [['d', 'book'], ['a', '30040:' . self::AUTHOR . ':index', '', self::CHILD]]);
        $this->expectException(NotFoundHttpException::class);
        try {
            $this->invoke([$source], '1', $method, policy: $policy);
        } finally {
            self::assertSame([], $this->queued);
        }
    }

    public static function blockedPolicies(): iterable
    {
        foreach (['hidden-source', 'muted-source', 'deleted-source', 'suppressed-source', 'hidden-target', 'muted-target', 'deleted-target', 'suppressed-target'] as $policy) {
            foreach (['GET', 'POST'] as $method) {
                yield $policy . '-' . $method => [$policy, $method];
            }
        }
    }

    public function testCompletionRendersOnlyRequestedOccurrenceWithAuthorizedMetadata(): void
    {
        $source = $this->event(self::SOURCE, self::OWNER, [
            ['d', 'book'], ['a', '30041:' . self::AUTHOR . ':section'], ['a', '30041:' . self::AUTHOR . ':section'],
        ]);
        $target = $this->event(self::CHILD, self::AUTHOR, [['d', 'section'], ['title', 'Section title']], 30041);
        $response = $this->invoke([$source], '2', 'GET', target: $target);
        $data = json_decode($response->getContent(), true);
        self::assertSame('available', $data['status']);
        self::assertSame('single-occurrence', $data['html']);
        self::assertSame(2, $this->rendered['entry']['reference']->position);
        self::assertSame('Section title', $this->rendered['entry']['target']['title']);
        self::assertSame([], $this->queued);
    }

    private function invoke(
        array $sources, string $position, string $method, array $query = [],
        string $sourceId = self::SOURCE, bool $csrfValid = true, ?string $policy = null, ?Event $target = null,
    ): \Symfony\Component\HttpFoundation\Response {
        $this->queued = [];
        $events = $this->createMock(EventRepository::class);
        $byId = [];
        foreach ($sources as $source) {
            $byId[$source->getId()] = $source;
        }
        $events->method('findById')->willReturnCallback(static fn (string $id) => $byId[$id] ?? null);
        $events->method('findPublicationIndexTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        $events->method('findWorkspacePreviewTargets')->willReturnCallback(
            static function (array $coordinates, array $ids) use ($target): array {
                self::assertSame(1, count($coordinates) + count($ids));
                return [
                    'coordinates' => $target === null ? [] : [$coordinates[0] => $target],
                    'eventIds' => [],
                ];
            },
        );
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturnCallback(static fn (string $id, int $kind, string $author): bool =>
            ($policy === 'deleted-source' && $author === self::OWNER) || ($policy === 'deleted-target' && $author === self::AUTHOR));
        $deletions->method('findSuppressedEventIds')->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturnCallback(static function (array $coordinates) use ($policy): array {
            foreach ($coordinates as $coordinate) {
                if (($policy === 'hidden-source' && str_contains($coordinate, self::OWNER))
                    || ($policy === 'hidden-target' && str_contains($coordinate, self::AUTHOR))) return [$coordinate];
            }
            return [];
        });
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('assertReadable')->willReturnCallback(static function (string $author) use ($policy): void {
            if (($policy === 'suppressed-source' && $author === self::OWNER)
                || ($policy === 'suppressed-target' && $author === self::AUTHOR)) throw new NotFoundHttpException();
        });
        $authors->method('suppressedPubkeys')->willReturn([]);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn(match ($policy) {
            'muted-source' => [self::OWNER], 'muted-target' => [self::AUTHOR], default => [],
        });
        $user = new User();
        $user->setNpub(PublicKey::fromHex(self::OWNER)->toBech32());
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $presentations = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $signer = new PublicationTocTraversalSigner('secret');
        $toc = new PublicationTocPage(
            new EventWorkspaceSourceAdapter(new RecordIdentityService()), $presentations, $events, $deletions, $authors, $hidden, $signer,
        );
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willReturn([1, 60]);
        $redis->method('ttl')->willReturn(60);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (FetchEventFromRelaysMessage $message): Envelope {
            $this->queued[] = $message;
            return new Envelope($message);
        });
        $relays = $this->createMock(RelayRegistry::class);
        $relays->method('getContentRelays')->willReturn(['wss://configured.example']);
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $container = new Container();
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container->set('security.token_storage', $storage);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($csrfValid);
        $container->set('security.csrf.token_manager', $csrf);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            self::assertSame('reader/reading_nook/_publication_toc_entry.html.twig', $template);
            $this->rendered = $parameters;
            return 'single-occurrence';
        });
        $container->set('twig', $twig);
        $controller->setContainer($container);
        $request = Request::create('/discovery', $method, []);
        $request->query->replace($query);
        $request->headers->set('X-CSRF-Token', 'csrf');
        return $controller->publicationTocDiscovery(
            $sourceId, $position, $request, $toc,
            new PublicationTocSourceAccess($events, $presentations, $deletions, $hidden, $authors), $signer,
            new TargetPreviewResolver($events, $deletions, $hidden, $authors, $presentations, $security, $mutes),
            new PublicationTocDiscovery($redis, $bus, $relays), $mutes,
        );
    }

    private function event(string $id, string $author, array $tags, int $kind = 30040): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($author);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent('Never expose body or media');
        return $event;
    }
}
