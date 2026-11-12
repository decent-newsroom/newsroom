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
use App\Service\EventWorkspace\FollowPackMemberPage;
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

final class FollowPackMembersControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testRouteIsReadOnlyAndRequiresRoleUser(): void
    {
        $method = new \ReflectionMethod(ReadingNookController::class, 'followPackMembers');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('reading_nook_follow_pack_members', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    public function testOwnedExactRevisionPagesIgnoreBrowserOwnerAndKind(): void
    {
        $event = $this->event();
        $tags = [['d', 'people']];
        for ($i = 0; $i < 51; ++$i) {
            $tags[] = ['p', self::OTHER];
        }
        $event->setTags($tags);
        $event->setContent('never expose this opaque content');
        $newerRevision = $this->event();
        $newerRevision->setId(str_repeat('d', 64));
        $newerRevision->setCreatedAt(456);
        $newerRevision->setTags([['d', 'people'], ['p', self::OWNER]]);
        $database = $this->database([$event, $newerRevision]);
        $captured = null;
        $controller = $this->controller(self::OWNER, $captured);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::ID, 39089, self::OWNER, 'people', 123)
            ->willReturn(false);
        $authorAccess = $this->createMock(ContentAuthorAccessPolicy::class);
        $authorAccess->expects(self::once())->method('assertReadable')->with(self::OWNER);

        $response = $controller->followPackMembers(
            self::ID,
            new Request(['page' => '2', 'pubkey' => self::OTHER, 'owner' => self::OTHER, 'kind' => '3']),
            $database,
            $this->pages(),
            $deletions,
            $authorAccess,
        );

        self::assertSame('member-fragment', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertSame(self::ID, $captured['memberPage']['eventId']);
        self::assertSame(2, $captured['memberPage']['page']);
        self::assertCount(1, $captured['memberPage']['members']);
        self::assertSame(51, $captured['memberPage']['members'][0]['position']);
        self::assertSame((new NostrNip19Service())->encodeNpub(self::OTHER), $captured['memberPage']['members'][0]['npub']);
        self::assertFalse($captured['memberPage']['hasNext']);
        self::assertTrue($captured['memberPage']['hasPrevious']);
        self::assertStringNotContainsString('never expose', serialize($captured));
        $database->close();
    }

    /** @dataProvider inaccessibleSources */
    public function testOtherOwnersKindsAndMissingEventsCannotExpand(?string $owner, int $kind): void
    {
        $database = $this->database($owner === null ? [] : [$this->event($owner, $kind)]);
        $captured = null;
        $controller = $this->controller(self::OWNER, $captured, false);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::never())->method('isSuppressed');

        $this->expectException(NotFoundHttpException::class);
        try {
            $controller->followPackMembers(
                self::ID,
                new Request(['owner' => $owner, 'kind' => (string) $kind]),
                $database,
                $this->pages(),
                $deletions,
                $this->createMock(ContentAuthorAccessPolicy::class),
            );
        } finally {
            $database->close();
        }
    }

    public static function inaccessibleSources(): iterable
    {
        yield 'other owner pack' => [self::OTHER, 39089];
        yield 'own follows' => [self::OWNER, 3];
        yield 'own index' => [self::OWNER, 30040];
        yield 'missing or removed' => [null, 39089];
    }

    /** @dataProvider policyCases */
    public function testSuppressionAndTombstonesDenyBeforeRendering(bool $authorSuppressed): void
    {
        $database = $this->database([$this->event()]);
        $captured = null;
        $controller = $this->controller(self::OWNER, $captured, false);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $authorAccess = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($authorSuppressed) {
            $authorAccess->expects(self::once())->method('assertReadable')
                ->with(self::OWNER)->willThrowException(new NotFoundHttpException());
            $deletions->expects(self::never())->method('isSuppressed');
        } else {
            $deletions->expects(self::once())->method('isSuppressed')->willReturn(true);
        }

        $this->expectException(NotFoundHttpException::class);
        try {
            $controller->followPackMembers(
                self::ID, new Request(), $database, $this->pages(), $deletions, $authorAccess,
            );
        } finally {
            $database->close();
        }
    }

    public static function policyCases(): iterable
    {
        yield 'suppressed author' => [true];
        yield 'tombstoned event' => [false];
    }

    /** @dataProvider invalidPages */
    public function testInvalidPagesCannotRender(string $page): void
    {
        $captured = null;
        $controller = $this->controller(self::OWNER, $captured, false);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $this->expectException(BadRequestHttpException::class);
        $controller->followPackMembers(
            self::ID, new Request(['page' => $page]), $em, $this->pages(),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
    }

    public static function invalidPages(): iterable
    {
        foreach (['0', '-1', '1.5', 'not-a-page', '9999999999999999999999999999'] as $page) {
            yield [$page];
        }
    }

    public function testDirectAnonymousInvocationCannotLoadMembers(): void
    {
        $captured = null;
        $controller = $this->controller(null, $captured, false);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $this->expectException(AccessDeniedException::class);
        $controller->followPackMembers(
            self::ID, new Request(), $em, $this->pages(),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
    }

    private function controller(?string $pubkey, ?array &$captured, bool $renders = true): ReadingNookController
    {
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::never())->method('getMetadata');
        $controller = new ReadingNookController($cache);
        $container = new Container();
        $storage = new TokenStorage();
        if ($pubkey !== null) {
            $user = new User();
            $user->setNpub(PublicKey::fromHex($pubkey)->toBech32());
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }
        $container->set('security.token_storage', $storage);
        $twig = $this->createMock(Environment::class);
        $twig->expects($renders ? self::once() : self::never())->method('render')
            ->willReturnCallback(static function (string $template, array $parameters) use (&$captured): string {
                self::assertSame('reader/reading_nook/_follow_pack_members.html.twig', $template);
                $captured = $parameters;

                return 'member-fragment';
            });
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function pages(): FollowPackMemberPage
    {
        $identities = new RecordIdentityService();
        $nip19 = new NostrNip19Service();

        return new FollowPackMemberPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, $nip19),
            $nip19,
        );
    }

    private function event(string $owner = self::OWNER, int $kind = 39089): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey($owner);
        $event->setKind($kind);
        $event->setCreatedAt(123);
        $event->setTags([['d', 'people'], ['p', self::OTHER]]);
        $event->setDTag('people');

        return $event;
    }

    /** @param list<Event> $events */
    private function database(array $events): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated member query requires pdo_sqlite.');
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
        foreach ($events as $event) {
            $database->persist($event);
        }
        $database->flush();
        $database->clear();

        return $database;
    }
}
