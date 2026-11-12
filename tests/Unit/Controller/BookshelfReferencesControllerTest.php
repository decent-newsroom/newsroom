<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Bookshelf\BookshelfReferencesController;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\BookshelfReferencePage;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

final class BookshelfReferencesControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testRouteIsAuthenticatedAndReadOnly(): void
    {
        $method = new \ReflectionMethod(BookshelfReferencesController::class, 'references');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('bookshelf_directory_references', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    public function testLatestAndExactPagesAlwaysScopeSourceToOwnerKindAndDirectory(): void
    {
        foreach ([[], ['eventId' => self::ID, 'page' => '2']] as $query) {
            $event = $this->event();
            $events = $this->createMock(EventRepository::class);
            $criteria = ['pubkey' => self::OWNER, 'kind' => 30045, 'dTag' => 'my-book-collection'];
            if ($query !== []) {
                $criteria['id'] = self::ID;
            }
            $events->expects(self::once())->method('findOneBy')
                ->with($criteria, ['created_at' => 'DESC', 'id' => 'ASC'])->willReturn($event);
            $deletions = $this->createMock(DeletedEventRepository::class);
            $deletions->expects(self::once())->method('isSuppressed')
                ->with(self::ID, 30045, self::OWNER, 'my-book-collection', 123)->willReturn(false);
            $access = $this->createMock(ContentAuthorAccessPolicy::class);
            $access->expects(self::once())->method('assertReadable')->with(self::OWNER);
            $hidden = $this->createMock(HiddenCoordinateRepository::class);
            $hidden->expects(self::once())->method('isHidden')
                ->with('30045:' . self::OWNER . ':my-book-collection')->willReturn(false);
            $captured = null;
            $response = $this->controller($captured)->references(
                new Request($query + ['owner' => str_repeat('c', 64), 'kind' => '30040']),
                $events, $this->pages(), $deletions, $access, $hidden,
            );
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame(self::ID, $captured['directoryPage']['eventId']);
            self::assertSame($query === [] ? 1 : 2, $captured['directoryPage']['page']);
            self::assertStringNotContainsString('private body', serialize($captured));
        }
    }

    public function testDatabaseSelectsLatestRevisionWithTieBreakAndKeepsPinnedOlderRevision(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated directory query requires pdo_sqlite.');
        }
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $database = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $database->getClassMetadata(Event::class)->setCustomRepositoryClass(\Doctrine\ORM\EntityRepository::class);
        (new SchemaTool($database))->createSchema([$database->getClassMetadata(Event::class)]);
        $latest = $this->event();
        $latest->setCreatedAt(200);
        $tied = clone $latest;
        $tied->setId(str_repeat('c', 64));
        $older = $this->event();
        $older->setId(str_repeat('0', 64));
        $foreign = clone $latest;
        $foreign->setId(str_repeat('d', 64));
        $foreign->setPubkey(str_repeat('e', 64));
        $foreign->setCreatedAt(300);
        $otherDirectory = clone $foreign;
        $otherDirectory->setId(str_repeat('f', 64));
        $otherDirectory->setPubkey(self::OWNER);
        $otherDirectory->setDTag('other-directory');
        foreach ([$latest, $tied, $older, $foreign, $otherDirectory] as $event) {
            $database->persist($event);
        }
        $database->flush();
        $database->clear();

        $events = $this->createMock(EventRepository::class);
        $events->method('findOneBy')->willReturnCallback($database->getRepository(Event::class)->findOneBy(...));
        $deletions = $this->createStub(DeletedEventRepository::class);
        $access = $this->createStub(ContentAuthorAccessPolicy::class);
        $hidden = $this->createStub(HiddenCoordinateRepository::class);
        $captured = null;
        try {
            $this->controller($captured)->references(new Request(), $events, $this->pages(), $deletions, $access, $hidden);
            self::assertSame(self::ID, $captured['directoryPage']['eventId']);
            $this->controller($captured)->references(
                new Request(['eventId' => str_repeat('0', 64)]),
                $events, $this->pages(), $deletions, $access, $hidden,
            );
            self::assertSame(str_repeat('0', 64), $captured['directoryPage']['eventId']);
            $this->expectException(NotFoundHttpException::class);
            $this->controller($captured, renders: false)->references(
                new Request(['eventId' => str_repeat('d', 64)]),
                $events, $this->pages(), $deletions, $access, $hidden,
            );
        } finally {
            $database->close();
        }
    }

    public function testMissingInitialDirectoryIsAnEmptyStateButMissingPinnedRevisionIsNotFound(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findOneBy')->willReturn(null);
        $captured = null;
        $this->controller($captured)->references(
            new Request(), $events, $this->pages(),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class),
        );
        self::assertNull($captured['directoryPage']);
        $this->expectException(NotFoundHttpException::class);
        $this->controller($captured, renders: false)->references(
            new Request(['eventId' => self::ID]), $events, $this->pages(),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class),
        );
    }

    /**
     * @dataProvider invalidQueries
     * @param array<string, mixed> $query
     */
    public function testInvalidPaginationNeverQueriesSource(array $query): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findOneBy');
        $captured = null;
        $this->expectException(BadRequestHttpException::class);
        $this->controller($captured, renders: false)->references(
            new Request($query), $events, $this->pages(),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class),
        );
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function invalidQueries(): iterable
    {
        yield [['page' => '0']];
        yield [['page' => '2']];
        yield [['page' => ['1']]];
        yield [['eventId' => ['bad']]];
        yield [['eventId' => 'bad']];
        yield [['page' => '999999999999999999999999999']];
    }

    /** @dataProvider deniedSources */
    public function testSourcePoliciesPreventRendering(string $policy): void
    {
        $event = $this->event();
        if ($policy === 'identifier') {
            $event->setTags([['d', 'different-directory'], ['d', 'my-book-collection']]);
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects(in_array($policy, ['hidden', 'author'], true) ? self::never() : self::once())
            ->method('findOneBy')->willReturn($event);
        $deletions = $this->createStub(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn($policy === 'deletion');
        $hidden = $this->createStub(HiddenCoordinateRepository::class);
        $hidden->method('isHidden')->willReturn($policy === 'hidden');
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($policy === 'author') {
            $access->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $captured = null;
        $this->expectException(NotFoundHttpException::class);
        $this->controller($captured, renders: false)->references(
            new Request(), $events, $this->pages(), $deletions, $access, $hidden,
        );
    }

    /** @return iterable<array{string}> */
    public static function deniedSources(): iterable
    {
        yield ['author'];
        yield ['hidden'];
        yield ['deletion'];
        yield ['identifier'];
    }

    public function testAnonymousDirectInvocationIsDenied(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findOneBy');
        $captured = null;
        $this->expectException(AccessDeniedException::class);
        $this->controller($captured, authenticated: false, renders: false)->references(
            new Request(), $events, $this->pages(),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class),
        );
    }

    /** @param array<string, mixed>|null $captured */
    private function controller(?array &$captured, bool $authenticated = true, bool $renders = true): BookshelfReferencesController
    {
        $storage = new TokenStorage();
        if ($authenticated) {
            $user = new User();
            $user->setNpub(PublicKey::fromHex(self::OWNER)->toBech32());
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }
        $twig = $this->createMock(Environment::class);
        $twig->expects($renders ? self::once() : self::never())->method('render')
            ->willReturnCallback(static function (string $template, array $parameters) use (&$captured): string {
                self::assertSame('bookshelf/_directory_references.html.twig', $template);
                $captured = $parameters;

                return 'directory-fragment';
            });
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('twig', $twig);
        $controller = new BookshelfReferencesController();
        $controller->setContainer($container);

        return $controller;
    }

    private function pages(): BookshelfReferencePage
    {
        $identities = new RecordIdentityService();

        return new BookshelfReferencePage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
        );
    }

    private function event(): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey(self::OWNER);
        $event->setKind(30045);
        $event->setDTag('my-book-collection');
        $event->setTags([['d', 'my-book-collection'], ['e', str_repeat('c', 64)]]);
        $event->setCreatedAt(123);
        $event->setContent('private body');

        return $event;
    }
}
