<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Bookshelf\BookshelfIndexSource;
use App\Controller\Bookshelf\IndexController;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Graph\RecordIdentityService;
use App\Service\Magazine\PublicationIndexClassifier;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class BookshelfIndexControllerTest extends TestCase
{
    private const ROOT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CHILD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const AUTHOR = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const FOREIGN = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    private EventRepository&MockObject $events;
    private DeletedEventRepository&MockObject $deletions;
    private HiddenCoordinateRepository&MockObject $hidden;
    private ContentAuthorAccessPolicy&MockObject $authors;
    private UserMuteListService&MockObject $mutes;
    private EventPresentationFactory $presentations;
    private PublicationTocTraversalSigner $signer;

    /** @var array<string, Event> */
    private array $stored = [];
    /** @var array<string, mixed>|null */
    private ?array $captured = null;
    private ?string $template = null;

    protected function setUp(): void
    {
        $this->events = $this->createMock(EventRepository::class);
        $this->events->method('findOneBy')->willReturnCallback(function (array $criteria): ?Event {
            self::assertSame(['id', 'kind'], array_keys($criteria));
            self::assertSame(30040, $criteria['kind']);
            $event = $this->stored[$criteria['id']] ?? null;

            return $event?->getKind() === 30040 ? $event : null;
        });
        $this->events->expects(self::never())->method('findByNaddr');
        $this->deletions = $this->createMock(DeletedEventRepository::class);
        $this->hidden = $this->createMock(HiddenCoordinateRepository::class);
        $this->authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $this->mutes = $this->createMock(UserMuteListService::class);
        $this->presentations = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $this->signer = new PublicationTocTraversalSigner('bookshelf-test-secret');
    }

    public function testRoutesArePublicReadOnlyAndUseExactIdentity(): void
    {
        foreach (['show' => 'bookshelf_index', 'toc' => 'bookshelf_index_toc'] as $method => $name) {
            $reflection = new \ReflectionMethod(IndexController::class, $method);
            $route = $reflection->getAttributes(Route::class)[0]->newInstance();
            self::assertSame($name, $route->name);
            self::assertSame(['GET'], $route->methods);
            self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
            self::assertSame([], $reflection->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class));
        }
    }

    public function testForeignParentStaysInBookshelfAndNestedPinnedContentsCanExpand(): void
    {
        $coordinate = '30040:' . self::FOREIGN . ':same:slug';
        $parent = $this->event(self::ROOT, self::AUTHOR, [
            ['d', 'same:slug'], ['a', $coordinate, '', self::CHILD], ['a', $coordinate, '', self::CHILD],
        ]);
        $child = $this->event(self::CHILD, self::FOREIGN, [
            ['d', 'same:slug'], ['title', 'Pinned foreign index'],
            ['a', '30041:' . self::FOREIGN . ':first'],
            ['a', '30041:' . self::FOREIGN . ':second'],
            ['a', '30040:' . self::AUTHOR . ':same:slug', '', self::ROOT],
        ]);
        $this->stored = [self::ROOT => $parent, self::CHILD => $child];
        $this->events->expects(self::exactly(2))->method('findPublicationIndexTargets')
            ->willReturnCallback(function (array $coordinates, array $ids) use ($child, $parent): array {
                self::assertSame([], $coordinates);
                foreach ($ids as $id) {
                    self::assertContains($id, [self::ROOT, self::CHILD]);
                }

                return ['coordinates' => [], 'eventIds' => [self::CHILD => $child, self::ROOT => $parent]];
            });
        $controller = $this->controller();
        $response = $controller->show(self::ROOT, new Request(), new PublicationIndexClassifier());
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->isRedirection());
        self::assertSame('bookshelf/index.html.twig', $this->template);
        self::assertSame('bookshelf_index_toc', $this->captured['tocNestedRoute']);
        self::assertFalse($this->captured['isBook']);
        self::assertSame(self::AUTHOR, $this->captured['presentation']->authorPubkey);
        $entries = $this->captured['tocPage']['entries'];
        self::assertSame([1, 2], array_column(array_column($entries, 'reference'), 'position'));
        self::assertSame(self::CHILD, $entries[0]['target']['eventId']);
        self::assertNotSame($entries[0]['nestedOccurrenceId'], $entries[1]['nestedOccurrenceId']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response = $controller->toc(self::CHILD, new Request([
            'path' => implode(',', $entries[0]['nestedPath']),
            'occurrence' => $entries[0]['nestedOccurrenceId'],
            'coordinate' => $coordinate,
            'signature' => $entries[0]['nestedSignature'],
        ]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('reader/reading_nook/_publication_toc.html.twig', $this->template);
        self::assertSame('bookshelf_index_toc', $this->captured['tocPage']['routeName']);
        self::assertSame(['first', 'second', 'same:slug'], array_column(
            array_column($this->captured['tocPage']['entries'], 'reference'), 'targetIdentifier',
        ));
        self::assertTrue($this->captured['tocPage']['entries'][2]['cycle']);
        self::assertNull($this->captured['tocPage']['entries'][2]['nestedOccurrenceId']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testCollidingAuthorsAndOldExactRevisionsAreNotReplacedBySlug(): void
    {
        $this->stored = [
            self::ROOT => $this->event(self::ROOT, self::AUTHOR, [['d', 'same']]),
            self::CHILD => $this->event(self::CHILD, self::FOREIGN, [['d', 'same']]),
        ];
        $this->events->expects(self::never())->method('findPublicationIndexTargets');
        foreach ([self::ROOT => self::AUTHOR, self::CHILD => self::FOREIGN] as $id => $author) {
            $this->controller()->show($id, new Request(), new PublicationIndexClassifier());
            self::assertSame($id, $this->captured['presentation']->eventId);
            self::assertSame($author, $this->captured['presentation']->authorPubkey);
            self::assertTrue($this->captured['isBook']);
        }
    }

    /** @dataProvider restrictedChildren */
    public function testRestrictedChildrenKeepOccurrenceWithoutMetadata(string $restriction): void
    {
        $coordinate = '30040:' . self::FOREIGN . ':child';
        $child = $this->event(self::CHILD, self::FOREIGN, [
            ['d', $restriction === 'mismatched pin' ? 'wrong-child' : 'child'], ['title', 'Must not leak'],
        ]);
        if ($restriction === 'draft') {
            $child->setKind(30024);
        }
        $this->stored = [
            self::ROOT => $this->event(self::ROOT, self::AUTHOR, [['d', 'root'], ['a', $coordinate, '', self::CHILD]]),
        ];
        $this->events->expects(self::once())->method('findPublicationIndexTargets')->with([], [self::CHILD])
            ->willReturn(['coordinates' => [], 'eventIds' => $restriction === 'missing' ? [] : [self::CHILD => $child]]);
        $this->hidden->method('findHiddenCoordinates')->willReturn($restriction === 'hidden' ? [$coordinate] : []);
        $this->authors->method('suppressedPubkeys')->willReturn($restriction === 'suppressed' ? [self::FOREIGN] : []);
        $this->deletions->method('findSuppressedEventIds')->willReturn($restriction === 'deleted' ? [self::CHILD] : []);
        $this->mutes->method('getMutedPubkeysStrict')->willReturn($restriction === 'muted' ? [self::FOREIGN] : []);

        $response = $this->controller(authenticated: true)->show(self::ROOT, new Request(), new PublicationIndexClassifier());
        self::assertSame(200, $response->getStatusCode());
        $entries = $this->captured['tocPage']['entries'];
        self::assertCount(1, $entries);
        self::assertSame(1, $entries[0]['reference']->position);
        self::assertSame(self::CHILD, $entries[0]['reference']->versionEventId);
        self::assertSame('unavailable', $entries[0]['targetResolution']);
        self::assertNull($entries[0]['target']);
        self::assertNull($entries[0]['nestedOccurrenceId']);
        self::assertStringNotContainsString('Must not leak', serialize($this->captured));
        self::assertStringNotContainsString('Do not process bodies', serialize($this->captured));
    }

    /** @return iterable<string, array{string}> */
    public static function restrictedChildren(): iterable
    {
        foreach (['hidden', 'suppressed', 'deleted', 'muted', 'draft', 'missing', 'mismatched pin'] as $restriction) {
            yield $restriction => [$restriction];
        }
    }

    /** @dataProvider restrictedSources */
    public function testSourcePolicyAppliesToDetailAndFragment(string $restriction, bool $fragment): void
    {
        $event = $this->event(self::CHILD, self::FOREIGN, [['d', 'child']]);
        if ($restriction === 'draft') {
            $event->setKind(30024);
        }
        $this->stored = [self::CHILD => $event, self::ROOT => $this->event(self::ROOT, self::AUTHOR, [['d', 'root']])];
        $this->hidden->method('isHidden')->willReturnCallback(
            static fn (string $coordinate): bool => $restriction === 'hidden'
                || ($restriction === 'ancestor' && $coordinate === '30040:' . self::AUTHOR . ':root'),
        );
        $this->deletions->method('isSuppressed')->willReturn($restriction === 'deleted');
        if ($restriction === 'suppressed') {
            $this->authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $this->mutes->method('getMutedPubkeysStrict')->willReturn($restriction === 'muted' ? [self::FOREIGN] : []);
        $this->events->expects(self::never())->method('findPublicationIndexTargets');
        $controller = $this->controller(true, false);
        $this->expectException(NotFoundHttpException::class);
        if ($fragment) {
            $controller->toc(self::CHILD, $this->signedRequest());
        } else {
            $controller->show(self::CHILD, new Request(), new PublicationIndexClassifier());
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function restrictedSources(): iterable
    {
        foreach (['draft', 'hidden', 'deleted', 'suppressed', 'muted'] as $restriction) {
            yield $restriction . ' detail' => [$restriction, false];
            yield $restriction . ' fragment' => [$restriction, true];
        }
        yield 'ancestor policy changed' => ['ancestor', true];
    }

    /**
     * @dataProvider invalidTraversal
     * @param array<string, mixed> $query
     */
    public function testInvalidTraversalCannotReadSources(array $query): void
    {
        $this->events->expects(self::never())->method('findOneBy');
        $this->expectException(BadRequestHttpException::class);
        $this->controller(renders: false)->toc(self::CHILD, new Request($query));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidTraversal(): iterable
    {
        yield 'empty' => [[]];
        yield 'array' => [['path' => [self::ROOT]]];
        yield 'unsigned' => [['path' => self::ROOT, 'occurrence' => self::ROOT, 'coordinate' => '30040:' . self::FOREIGN . ':child', 'signature' => self::ROOT]];
        yield 'depth' => [['path' => implode(',', array_fill(0, 6, self::ROOT)), 'occurrence' => self::ROOT, 'coordinate' => 'x', 'signature' => self::ROOT]];
    }

    public function testSignedCoordinateMismatchCannotRenderOrFallBack(): void
    {
        $this->stored = [
            self::ROOT => $this->event(self::ROOT, self::AUTHOR, [['d', 'root']]),
            self::CHILD => $this->event(self::CHILD, self::AUTHOR, [['d', 'child']]),
        ];
        $this->expectException(NotFoundHttpException::class);
        $this->controller(renders: false)->toc(self::CHILD, $this->signedRequest());
    }

    public function testExpansionRollbackDisablesOnlyFragment(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller(renders: false, enabled: false)->toc(self::CHILD, new Request());
    }

    public function testSignedCycleCannotRenderSourceAgain(): void
    {
        $this->stored = [self::CHILD => $this->event(self::CHILD, self::FOREIGN, [['d', 'child']])];
        $coordinate = '30040:' . self::FOREIGN . ':child';
        $this->expectException(NotFoundHttpException::class);
        $this->controller(renders: false)->toc(self::CHILD, new Request([
            'path' => self::CHILD, 'occurrence' => self::ROOT, 'coordinate' => $coordinate,
            'signature' => $this->signer->sign(self::CHILD, 1, [self::CHILD], self::ROOT, $coordinate),
        ]));
    }

    private function signedRequest(): Request
    {
        $coordinate = '30040:' . self::FOREIGN . ':child';

        return new Request([
            'path' => self::ROOT, 'occurrence' => self::ROOT, 'coordinate' => $coordinate,
            'signature' => $this->signer->sign(self::CHILD, 1, [self::ROOT], self::ROOT, $coordinate),
        ]);
    }

    private function controller(bool $authenticated = false, bool $renders = true, bool $enabled = true): IndexController
    {
        $identities = new RecordIdentityService();
        $controller = new IndexController(
            new BookshelfIndexSource($this->events, $this->presentations, $this->authors, $this->deletions, $this->hidden),
            $this->presentations,
            new PublicationTocPage(
                new EventWorkspaceSourceAdapter($identities), $this->presentations, $this->events,
                $this->deletions, $this->authors, $this->hidden, $this->signer,
            ),
            $this->signer, $this->mutes, $enabled,
        );
        $container = new Container();
        $storage = new TokenStorage();
        if ($authenticated) {
            $user = new User();
            $user->setNpub(PublicKey::fromHex(self::AUTHOR)->toBech32());
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }
        $container->set('security.token_storage', $storage);
        $twig = $this->createMock(Environment::class);
        $twig->expects($renders ? self::atLeastOnce() : self::never())->method('render')
            ->willReturnCallback(function (string $template, array $parameters): string {
                $this->template = $template;
                $this->captured = $parameters;

                return 'bookshelf-index';
            });
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    /** @param list<list<string>> $tags */
    private function event(string $id, string $author, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($author);
        $event->setKind(30040);
        $event->setTags($tags);
        $event->setContent('<script>Do not process bodies</script>');
        $event->setCreatedAt(123);

        return $event;
    }
}
