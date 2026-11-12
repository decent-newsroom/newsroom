<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocPage;
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
                $database, $this->pages(), $deletions, $access,
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
            $controller->publicationToc(self::ID, new Request(), $database, $this->pages(), $deletions, $access);
        } finally {
            $database->close();
        }
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
        );
    }

    private function controller(?array &$captured, bool $renders = true, bool $authenticated = true): ReadingNookController
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
            ->willReturnCallback(static function (string $template, array $parameters) use (&$captured): string {
                self::assertSame('reader/reading_nook/_publication_toc.html.twig', $template);
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

        return new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
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
