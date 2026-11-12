<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\BookmarkReferencePage;
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

final class BookmarkReferencesControllerTest extends TestCase
{
    use \App\Tests\Unit\Service\EventWorkspace\TargetPreviewResolverTestTrait;
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testRouteIsAuthenticatedAndReadOnly(): void
    {
        $method = new \ReflectionMethod(ReadingNookController::class, 'bookmarkReferences');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('reading_nook_bookmark_references', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    /** @dataProvider supportedKinds */
    public function testOwnerCanPageExactBookmarkRevision(int $kind): void
    {
        $event = $this->event(self::OWNER, $kind);
        $event->setTags([['title', 'Saved'], ['e', self::OTHER]]);
        $database = $this->database([$event]);
        $captured = null;
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::ID, $kind, self::OWNER, null, 123)->willReturn(false);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('assertReadable')->with(self::OWNER);

        try {
            $response = $this->controller(self::OWNER, $captured)->bookmarkReferences(
                self::ID,
                new Request(['page' => '1', 'pubkey' => self::OTHER, 'kind' => '3']),
                $database,
                $this->pages(),
                $deletions,
                $access,
            );
            self::assertSame('bookmark-fragment', $response->getContent());
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame(self::ID, $captured['bookmarkPage']['eventId']);
            self::assertSame((new NostrNip19Service())->encodeNote(self::OTHER), $captured['bookmarkPage']['entries'][0]['value']);
            self::assertSame('restricted', $captured['bookmarkPage']['entries'][0]['targetResolution']->status->value);
            self::assertNull($captured['bookmarkPage']['entries'][0]['targetResolution']->presentation);
        } finally {
            $database->close();
        }
    }

    /** @return iterable<string, array{int}> */
    public static function supportedKinds(): iterable
    {
        yield 'standard bookmarks' => [10003];
        yield 'bookmark set' => [30003];
    }

    /** @dataProvider inaccessibleSources */
    public function testOtherOwnersAndKindsCannotExpand(?string $owner, int $kind): void
    {
        $database = $owner === null ? $this->database([]) : $this->database([$this->event($owner, $kind)]);
        $captured = null;
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::never())->method('isSuppressed');

        try {
            $this->expectException(NotFoundHttpException::class);
            $this->controller(self::OWNER, $captured)->bookmarkReferences(
                self::ID, new Request(), $database, $this->pages(), $deletions,
                $this->createMock(ContentAuthorAccessPolicy::class),
            );
        } finally {
            $database->close();
        }
    }

    /** @return iterable<string, array{?string, int}> */
    public static function inaccessibleSources(): iterable
    {
        yield 'other owner bookmark' => [self::OTHER, 10003];
        yield 'own follows' => [self::OWNER, 3];
        yield 'own follow pack' => [self::OWNER, 39089];
        yield 'missing' => [null, 10003];
    }

    public function testInvalidPageIsRejectedBeforeDatabaseLookup(): void
    {
        $captured = null;
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $this->expectException(BadRequestHttpException::class);
        $this->controller(self::OWNER, $captured)->bookmarkReferences(
            self::ID,
            new Request(['page' => '0']),
            $em,
            $this->pages(),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
    }

    public function testAnonymousDirectInvocationIsDeniedBeforeDatabaseLookup(): void
    {
        $captured = null;
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');

        $this->expectException(AccessDeniedException::class);
        $this->controller(null, $captured)->bookmarkReferences(
            self::ID,
            new Request(),
            $em,
            $this->pages(),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
    }

    /** @param array<string, mixed>|null $captured */
    private function controller(?string $pubkey, ?array &$captured): ReadingNookController
    {
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $container = new Container();
        $storage = new TokenStorage();
        if ($pubkey !== null) {
            $user = new User();
            $user->setNpub(PublicKey::fromHex($pubkey)->toBech32());
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }
        $container->set('security.token_storage', $storage);
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $parameters) use (&$captured): string {
            self::assertSame('reader/reading_nook/_bookmark_references.html.twig', $template);
            $captured = $parameters;

            return 'bookmark-fragment';
        });
        $container->set('twig', $twig);
        $controller->setContainer($container);

        return $controller;
    }

    private function pages(): BookmarkReferencePage
    {
        $identities = new RecordIdentityService();
        $nip19 = new NostrNip19Service();

        return new BookmarkReferencePage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, $nip19),
            $nip19,
            $this->targetResolver(),
        );
    }

    private function event(string $owner, int $kind): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey($owner);
        $event->setKind($kind);
        $event->setCreatedAt(123);

        return $event;
    }

    /** @param list<Event> $events */
    private function database(array $events): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated bookmark query requires pdo_sqlite.');
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
