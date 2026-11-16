<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationChapterPreview;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocSourceAccess;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener;
use Twig\Environment;
use AsciiDocConverter;
use Psr\Log\LoggerInterface;

final class PublicationTocControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const PIN = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @var list<string> */
    private array $queries = [];

    public function testReadOnlyRouteRequiresUserAndExactEventId(): void
    {
        $method = new \ReflectionMethod(ReadingNookController::class, 'publicationToc');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('reading_nook_publication_toc', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    public function testChapterPreviewRouteIsASeparateAuthenticatedGetFragment(): void
    {
        $method = new \ReflectionMethod(ReadingNookController::class, 'publicationTocChapterPreview');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('reading_nook_publication_toc_chapter_preview', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
        self::assertSame('\d+', $route->requirements['position']);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    public function testChapterPreviewReauthorizesTheSourceAndReturnsPrivateFragment(): void
    {
        $source = $this->event();
        $author = str_repeat('d', 64);
        $coordinate = '30041:' . $author . ':chapter';
        $source->setTags([['d', 'book'], ['a', $coordinate]]);
        $target = $this->event(self::PIN, 30041);
        $target->setId(self::PIN);
        $target->setPubkey($author);
        $target->setDTag('chapter');
        $target->setCreatedAt(456);
        $target->setTags([['d', 'chapter'], ['title', 'Preview title'], ['summary', 'Preview summary']]);
        $target->setContent("== Chapter body\n\nRendered on demand.");
        $metadata = clone $target;
        $metadata->setContent('');
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $metadata], 'eventIds' => []]);
        $events->expects(self::exactly(2))->method('findById')
            ->willReturnMap([[self::ID, $source], [self::PIN, $target]]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::exactly(2))->method('findHiddenCoordinates')->willReturn([]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::exactly(2))->method('assertReadable');
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::exactly(2))->method('isSuppressed')->willReturn(false);
        $sourceAccess = new PublicationTocSourceAccess(
            $events,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $deletions,
            $hidden,
            $authors,
        );
        $chapterPreview = new PublicationChapterPreview(
            $events,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $sourceAccess,
        );
        $muted = $this->createMock(UserMuteListService::class);
        $muted->expects(self::once())->method('getMutedPubkeysStrict')->with(self::OWNER)->willReturn([]);
        $captured = null;
        $controller = $this->controller(
            $captured,
            true,
            true,
            'reader/reading_nook/_publication_chapter_preview.html.twig',
        );
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $targetOccurrence = hash('sha256', 'root:' . self::ID . ':chapter:1:' . self::PIN);

        $response = $controller->publicationTocChapterPreview(
            self::ID,
            '1',
            new Request([
                'target' => self::PIN,
                'targetOccurrence' => $targetOccurrence,
                'targetCoordinate' => $coordinate,
                'targetSignature' => $signer->sign(self::PIN, 1, [self::ID], $targetOccurrence, $coordinate),
            ]),
            $sourceAccess,
            $chapterPreview,
            $signer,
            $muted,
            new AsciiDocConverter(),
            $this->createMock(LoggerInterface::class),
        );

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame('Preview title', $captured['chapter']['title']);
        self::assertSame('Preview summary', $captured['chapter']['summary']);
        self::assertStringContainsString('Chapter body', $captured['contentHtml']);
        self::assertSame('nook-chapter-' . self::ID . '-1', $captured['frameId']);
    }

    public function testScopedChapterIsDeniedAtTheFragmentBeforeBodyLookup(): void
    {
        $source = $this->event();
        $author = str_repeat('d', 64);
        $coordinate = '30041:' . $author . ':chapter';
        $source->setTags([['d', 'book'], ['a', $coordinate, '', self::PIN]]);
        $target = $this->event($author, 30041);
        $target->setId(self::PIN);
        $target->setDTag('chapter');
        $target->setTags([['d', 'chapter'], ['s', 'members'], ['title', 'Secret title']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn($source);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')->with([], [self::PIN])
            ->willReturn(['coordinates' => [], 'eventIds' => [self::PIN => $target]]);
        $presentations = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $access = new PublicationTocSourceAccess(
            $events, $presentations, $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class), $this->createStub(ContentAuthorAccessPolicy::class),
        );
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $targetOccurrence = hash('sha256', 'root:' . self::ID . ':chapter:1:' . self::PIN);
        $captured = null;
        $this->expectException(NotFoundHttpException::class);
        $this->controller($captured, false)->publicationTocChapterPreview(
            self::ID, '1', new Request([
                'target' => self::PIN, 'targetOccurrence' => $targetOccurrence, 'targetCoordinate' => $coordinate,
                'targetSignature' => $signer->sign(self::PIN, 1, [self::ID], $targetOccurrence, $coordinate),
            ]), $access, new PublicationChapterPreview($events, $presentations, $access), $signer,
            $this->mutedList(), new AsciiDocConverter(), $this->createMock(LoggerInterface::class),
        );
    }

    public function testTamperedNestedChapterPreviewSignatureFailsBeforeSourceLookup(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $sourceAccess = new PublicationTocSourceAccess(
            $events,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
        );
        $chapterPreview = new PublicationChapterPreview(
            $events,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $sourceAccess,
        );
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $occurrence = str_repeat('f', 64);
        $coordinate = '30040:' . str_repeat('d', 64) . ':nested';
        $muted = $this->createMock(UserMuteListService::class);
        $muted->expects(self::never())->method('getMutedPubkeysStrict');
        $captured = null;
        $this->expectException(BadRequestHttpException::class);
        $this->controller($captured, false)->publicationTocChapterPreview(
            self::PIN,
            '1',
            new Request([
                'page' => '1',
                'path' => self::ID,
                'occurrence' => $occurrence,
                'coordinate' => $coordinate,
                'signature' => $signer->sign(self::ID, 1, [self::ID], $occurrence, $coordinate),
            ]),
            $sourceAccess,
            $chapterPreview,
            $signer,
            $muted,
            new AsciiDocConverter(),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testUnpinnedChapterPreviewWithoutSignedLocalResolutionFailsBeforeLookup(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $sourceAccess = new PublicationTocSourceAccess(
            $events,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
        );
        $chapterPreview = new PublicationChapterPreview(
            $events,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $sourceAccess,
        );
        $muted = $this->createMock(UserMuteListService::class);
        $muted->expects(self::never())->method('getMutedPubkeysStrict');
        $captured = null;
        $this->expectException(BadRequestHttpException::class);
        $this->controller($captured, false)->publicationTocChapterPreview(
            self::ID,
            '1',
            new Request(),
            $sourceAccess,
            $chapterPreview,
            new PublicationTocTraversalSigner('test-framework-secret'),
            $muted,
            new AsciiDocConverter(),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testNestedRouteIsAnAuthenticatedExactEventFragment(): void
    {
        $method = new \ReflectionMethod(ReadingNookController::class, 'nestedPublicationToc');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('reading_nook_nested_publication_toc', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);

        $child = $this->event(self::PIN, 30040);
        $child->setId(self::PIN);
        $child->setPubkey(str_repeat('d', 64));
        $child->setDTag('nested');
        $child->setTags([
            ['d', 'nested'],
            ['a', '30040:' . str_repeat('e', 64) . ':missing'],
        ]);
        $database = $this->database([$child]);
        $captured = null;
        $controller = $this->controller($captured);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::PIN, 30040, str_repeat('d', 64), 'nested', 123)->willReturn(false);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('assertReadable')->with(str_repeat('d', 64));
        $coordinate = '30040:' . str_repeat('d', 64) . ':nested';
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $muted = $this->createMock(UserMuteListService::class);
        $muted->expects(self::once())->method('getMutedPubkeysStrict')->with(self::OWNER)->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('findHiddenCoordinates')->with([$coordinate])->willReturn([]);
        $root = $this->event();
        $root->setTags([['d', 'book'], ['a', $coordinate, '', self::PIN]]);

        try {
            $response = $controller->nestedPublicationToc(
                self::PIN,
                new Request([
                    'page' => '1',
                    'path' => self::ID,
                    'occurrence' => str_repeat('f', 64),
                    'coordinate' => $coordinate,
                    'signature' => $signer->sign(self::PIN, 1, [self::ID], str_repeat('f', 64), $coordinate),
                ]),
                $database,
                $this->pages(),
                $deletions,
                $access,
                $hidden,
                $muted,
                $signer,
                $this->sourceAccess([$root, $child]),
            );
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame('nook-toc-nested-' . str_repeat('f', 64), $captured['tocPage']['frameId']);
            self::assertSame([self::ID, self::PIN], $captured['tocPage']['path']);
            self::assertSame('reading_nook_nested_publication_toc', $captured['tocPage']['routeName']);
        } finally {
            $database->close();
        }
    }

    public function testTamperedOrResetTraversalStateIsRejectedBeforeSourceQuery(): void
    {
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $coordinate = '30040:' . str_repeat('d', 64) . ':nested';
        $occurrence = str_repeat('f', 64);
        $signature = $signer->sign(self::PIN, 1, [self::ID], $occurrence, $coordinate);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        foreach ([
            [self::PIN, ['page' => '2', 'path' => self::ID]],
            [self::PIN, ['page' => '1', 'path' => str_repeat('e', 64)]],
            [self::PIN, ['page' => '1', 'path' => '']],
            [self::PIN, ['page' => '1', 'path' => [self::ID]]],
            [str_repeat('a', 64), ['page' => '1', 'path' => self::ID]],
            [self::PIN, ['page' => '1', 'path' => self::ID, 'occurrence' => str_repeat('a', 64)]],
            [self::PIN, ['page' => '1', 'path' => self::ID, 'coordinate' => '30040:' . str_repeat('e', 64) . ':nested']],
        ] as $tampered) {
            $captured = null;
            [$routeEventId, $changedQuery] = $tampered;
            $request = new Request($changedQuery + [
                'occurrence' => $occurrence,
                'coordinate' => $coordinate,
                'signature' => $signature,
            ]);
            try {
                $this->controller($captured, false)->nestedPublicationToc(
                    $routeEventId,
                    $request,
                    $em,
                    $this->pages(),
                    $this->createMock(DeletedEventRepository::class),
                    $this->createMock(ContentAuthorAccessPolicy::class),
                    $this->createStub(HiddenCoordinateRepository::class),
                    $this->mutedList(),
                    $signer,
                    $this->sourceAccess(),
                );
                self::fail('Tampered traversal state must be rejected.');
            } catch (BadRequestHttpException) {
                self::assertNull($captured);
            }
        }
    }

    public function testHiddenAndReaderMutedNestedSourcesAreRejectedBeforeEventLookup(): void
    {
        $coordinate = '30040:' . str_repeat('d', 64) . ':nested';
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $signature = $signer->sign(self::PIN, 1, [self::ID], str_repeat('f', 64), $coordinate);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('findHiddenCoordinates')->with([$coordinate])->willReturn([$coordinate]);
        $captured = null;
        $this->expectException(NotFoundHttpException::class);
        $this->controller($captured, false)->nestedPublicationToc(
            self::PIN,
            new Request([
                'path' => self::ID,
                'occurrence' => str_repeat('f', 64),
                'coordinate' => $coordinate,
                'signature' => $signature,
            ]),
            $em,
            $this->pages(),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
            $hidden,
            $this->mutedList(),
            $signer,
            $this->sourceAccess(),
        );
    }

    public function testMutedNestedSourceIsRejectedBeforeEventLookup(): void
    {
        $coordinate = '30040:' . str_repeat('d', 64) . ':nested';
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $signature = $signer->sign(self::PIN, 1, [self::ID], str_repeat('f', 64), $coordinate);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $muted = $this->createMock(UserMuteListService::class);
        $muted->expects(self::once())->method('getMutedPubkeysStrict')->with(self::OWNER)
            ->willReturn([str_repeat('d', 64)]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::never())->method('findHiddenCoordinates');
        $captured = null;
        $this->expectException(NotFoundHttpException::class);
        $this->controller($captured, false)->nestedPublicationToc(
            self::PIN,
            new Request([
                'path' => self::ID,
                'occurrence' => str_repeat('f', 64),
                'coordinate' => $coordinate,
                'signature' => $signature,
            ]),
            $em,
            $this->pages(),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
            $hidden,
            $muted,
            $signer,
            $this->sourceAccess(),
        );
    }

    /** @dataProvider roleCases */
    public function testRoleGuardRejectsAnonymousAndAuthenticatedWithoutRole(?array $roles, bool $allowed): void
    {
        $storage = new TokenStorage();
        if ($roles !== null) {
            $storage->setToken(new UsernamePasswordToken(new InMemoryUser('viewer', null, $roles), 'main', $roles));
        }
        $captured = null;
        $event = new ControllerArgumentsEvent(
            $this->createMock(HttpKernelInterface::class),
            [$this->controller($captured, false), 'publicationToc'],
            [], Request::create('/reading-nook/publication-indexes/' . self::ID . '/toc'),
            HttpKernelInterface::MAIN_REQUEST,
        );
        $listener = new IsGrantedAttributeListener(
            new AuthorizationChecker($storage, new AccessDecisionManager([new RoleVoter()])),
        );
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }
        $listener->onKernelControllerArguments($event);
        self::assertTrue($allowed);
    }

    public static function roleCases(): iterable
    {
        yield 'anonymous' => [null, false];
        yield 'authenticated without role' => [['ROLE_OTHER'], false];
        yield 'user' => [['ROLE_USER'], true];
    }

    public function testExactOwnedRevisionIsPagedWithoutTargetQueriesOrLatestSubstitution(): void
    {
        $event = $this->event();
        $event->setTags([['d', 'book'], ...array_fill(0, 51, ['a', '30040:' . self::OWNER . ':nested', '', self::PIN])]);
        $newer = $this->event();
        $newer->setId(str_repeat('d', 64));
        $newer->setCreatedAt(456);
        $newer->setTags([['d', 'book'], ['a', '30041:' . self::OWNER . ':newer']]);
        $database = $this->database([$event, $newer]);
        $captured = null;
        $controller = $this->controller($captured);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::ID, 30040, self::OWNER, 'book', 123)->willReturn(false);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('assertReadable')->with(self::OWNER);

        try {
            $response = $controller->publicationToc(
                self::ID, new Request(['page' => '2', 'owner' => self::PIN, 'kind' => '3']),
                $database, $this->pages(), $deletions, $access, $this->mutedList(),
            );
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame(self::ID, $captured['tocPage']['eventId']);
            self::assertCount(1, $captured['tocPage']['entries']);
            $reference = $captured['tocPage']['entries'][0]['reference'];
            self::assertSame(51, $reference->position);
            self::assertSame(30040, $reference->targetKind);
            self::assertSame(self::PIN, $reference->versionEventId);
            self::assertSame('unresolved', $reference->status->value);
            self::assertStringNotContainsString('never expose', serialize($captured));
            self::assertCount(1, $this->queries, 'Only the exact source is queried, never its targets.');
        } finally {
            $database->close();
        }
    }

    /** @dataProvider blockedSources */
    public function testWrongOwnerKindMissingDeletedAndSuppressedSourcesAreBlocked(
        ?string $owner, int $kind, bool $deleted, bool $suppressed,
    ): void {
        $database = $this->database($owner === null ? [] : [$this->event($owner, $kind)]);
        $captured = null;
        $controller = $this->controller($captured, false);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects($owner === self::OWNER && $kind === 30040 && !$suppressed ? self::once() : self::never())
            ->method('isSuppressed')->willReturn($deleted);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('assertReadable')->with(self::OWNER)
            ->willReturnCallback(static function () use ($suppressed): void {
                if ($suppressed) {
                    throw new NotFoundHttpException();
                }
            });
        $this->expectException(NotFoundHttpException::class);
        try {
            $controller->publicationToc(self::ID, new Request(), $database, $this->pages(), $deletions, $access, $this->mutedList());
        } finally {
            $database->close();
        }
    }

    public function testScopedOwnedRootCannotRenderToc(): void
    {
        $root = $this->event();
        $root->setTags([['d', 'book'], ['s', 'members'], ['title', 'Secret title']]);
        $database = $this->database([$root]);
        $captured = null;
        try {
            $this->controller($captured, false)->publicationToc(
                self::ID, new Request(), $database, $this->pages(),
                $this->createMock(DeletedEventRepository::class),
                $this->createMock(ContentAuthorAccessPolicy::class), $this->mutedList(),
            );
            self::fail('A scoped root must not render.');
        } catch (NotFoundHttpException) {
            self::assertNull($captured);
        } finally {
            $database->close();
        }
    }

    /** @dataProvider scopedNestedSources */
    public function testNestedTocRechecksEverySourceAndExactEdge(string $scopedSource): void
    {
        $coordinate = '30040:' . str_repeat('d', 64) . ':nested';
        $root = $this->event();
        $root->setTags([['d', 'book'],
            ...($scopedSource === 'removed edge' ? [] : [['a', $coordinate, '', $scopedSource === 'changed pin' ? str_repeat('9', 64) : self::PIN]]),
            ...($scopedSource === 'root' ? [['s', 'members']] : [])]);
        $child = $this->event();
        $child->setId(self::PIN);
        $child->setPubkey(str_repeat('d', 64));
        $child->setDTag('nested');
        $child->setTags([['d', 'nested'], ...($scopedSource === 'child' ? [['s']] : [])]);
        $database = $this->database([$root, $child]);
        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $captured = null;
        try {
            $this->controller($captured, false)->nestedPublicationToc(
                self::PIN,
                new Request(['path' => self::ID, 'occurrence' => str_repeat('f', 64), 'coordinate' => $coordinate,
                    'signature' => $signer->sign(self::PIN, 1, [self::ID], str_repeat('f', 64), $coordinate)]),
                $database, $this->pages(), $this->createStub(DeletedEventRepository::class),
                $this->createStub(ContentAuthorAccessPolicy::class),
                $this->createStub(HiddenCoordinateRepository::class), $this->mutedList(), $signer,
                $this->sourceAccess([$root, $child]),
            );
            self::fail('Every source and exact edge in a signed path must remain readable.');
        } catch (NotFoundHttpException) {
            self::assertNull($captured);
        } finally {
            $database->close();
        }
    }

    public static function scopedNestedSources(): iterable
    {
        yield 'scoped ancestor' => ['root'];
        yield 'scoped exact source' => ['child'];
        yield 'removed edge' => ['removed edge'];
        yield 'changed exact pin' => ['changed pin'];
    }

    public static function blockedSources(): iterable
    {
        yield 'other owner' => [self::PIN, 30040, false, false];
        yield 'follow pack' => [self::OWNER, 39089, false, false];
        yield 'contact list' => [self::OWNER, 3, false, false];
        yield 'directory' => [self::OWNER, 30045, false, false];
        yield 'missing' => [null, 30040, false, false];
        yield 'deleted' => [self::OWNER, 30040, true, false];
        yield 'author suppressed' => [self::OWNER, 30040, false, true];
    }

    /** @dataProvider invalidPages */
    public function testInvalidPagesFailBeforeDatabaseAccess(string $page): void
    {
        $captured = null;
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $this->expectException(BadRequestHttpException::class);
        $this->controller($captured, false)->publicationToc(
            self::ID, new Request(['page' => $page]), $em, $this->pages(),
            $this->createMock(DeletedEventRepository::class), $this->createMock(ContentAuthorAccessPolicy::class),
            $this->mutedList(),
        );
    }

    public static function invalidPages(): iterable
    {
        foreach (['0', '-1', '1.5', '1e2', '01', '', 'x', '99999999999999999999999999999'] as $page) {
            yield [$page];
        }
    }

    public function testAnonymousDirectCallCannotQuerySource(): void
    {
        $captured = null;
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $this->expectException(AccessDeniedException::class);
        $this->controller($captured, false, false)->publicationToc(
            self::ID, new Request(), $em, $this->pages(),
            $this->createMock(DeletedEventRepository::class), $this->createMock(ContentAuthorAccessPolicy::class),
            $this->mutedList(),
        );
    }

    private function controller(
        ?array &$captured,
        bool $renders = true,
        bool $authenticated = true,
        string $expectedTemplate = 'reader/reading_nook/_publication_toc.html.twig',
    ): ReadingNookController
    {
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::never())->method('getMetadata');
        $controller = new ReadingNookController($cache);
        $container = new Container();
        $storage = new TokenStorage();
        if ($authenticated) {
            $user = new User();
            $user->setNpub(PublicKey::fromHex(self::OWNER)->toBech32());
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }
        $container->set('security.token_storage', $storage);
        $twig = $this->createMock(Environment::class);
        $twig->expects($renders ? self::once() : self::never())->method('render')
            ->willReturnCallback(static function (string $template, array $parameters) use (&$captured, $expectedTemplate): string {
                self::assertSame($expectedTemplate, $template);
                $captured = $parameters;

                return 'toc-fragment';
            });
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function pages(): PublicationTocPage
    {
        $identities = new RecordIdentityService();
        $events = $this->createMock(EventRepository::class);
        $events->method('findPublicationIndexTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        $authorAccess = $this->createMock(ContentAuthorAccessPolicy::class);
        $deletions = $this->createMock(DeletedEventRepository::class);

        return new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
            $events,
            $deletions,
            $authorAccess,
            $this->createStub(HiddenCoordinateRepository::class),
            new PublicationTocTraversalSigner('test-framework-secret'),
        );
    }

    private function mutedList(): UserMuteListService
    {
        $service = $this->createMock(UserMuteListService::class);
        $service->method('getMutedPubkeysStrict')->willReturn([]);

        return $service;
    }

    /** @param list<Event> $sources */
    private function sourceAccess(array $sources = []): PublicationTocSourceAccess
    {
        $events = $this->createMock(EventRepository::class);
        $byId = [];
        foreach ($sources as $source) {
            $byId[$source->getId()] = $source;
        }
        $events->method('findById')->willReturnCallback(static fn (string $id): ?Event => $byId[$id] ?? null);
        return new PublicationTocSourceAccess(
            $events, new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
        );
    }

    private function event(string $owner = self::OWNER, int $kind = 30040): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey($owner);
        $event->setKind($kind);
        $event->setCreatedAt(123);
        $event->setDTag('book');
        $event->setTags([['d', 'book']]);
        $event->setContent('never expose source body');

        return $event;
    }

    private function database(array $events): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated source query requires pdo_sqlite.');
        }
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $logger = new class(function (string $sql): void { $this->queries[] = $sql; }) extends \Psr\Log\AbstractLogger {
            public function __construct(private readonly \Closure $record) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    ($this->record)($context['sql']);
                }
            }
        };
        $connectionConfig = new \Doctrine\DBAL\Configuration();
        $connectionConfig->setMiddlewares([new \Doctrine\DBAL\Logging\Middleware($logger)]);
        $database = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $connectionConfig), $config);
        $database->getClassMetadata(Event::class)->setCustomRepositoryClass(\Doctrine\ORM\EntityRepository::class);
        (new SchemaTool($database))->createSchema([$database->getClassMetadata(Event::class)]);
        foreach ($events as $event) {
            $database->persist($event);
        }
        $database->flush();
        $database->clear();
        $this->queries = [];

        return $database;
    }
}
