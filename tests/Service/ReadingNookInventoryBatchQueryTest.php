<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\BannedPubkeyRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\DriveMountAccess;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class ReadingNookInventoryBatchQueryTest extends KernelTestCase
{
    public function testFullInventoryUsesBoundedPolicyAndSubscriptionQueries(): void
    {
        self::bootKernel();
        $queries = [];
        $logger = new class(static function (string $sql) use (&$queries): void { $queries[] = $sql; }) extends AbstractLogger {
            public function __construct(private readonly \Closure $record) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (isset($context['sql'])) {
                    ($this->record)($context['sql']);
                }
            }
        };
        $dbal = new Configuration();
        $dbal->setMiddlewares([new Middleware($logger)]);
        $params = self::getContainer()->get('doctrine')->getConnection()->getParams();
        $connection = DriverManager::getConnection($params, $dbal);
        $orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Entity'], true);
        $orm->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $orm->enableNativeLazyObjects(true);
        } else {
            $orm->setProxyDir(sys_get_temp_dir());
            $orm->setProxyNamespace('Tests\DoctrineProxies');
        }
        $em = new EntityManager($connection, $orm);
        try {
            $connection->executeStatement('CREATE TEMPORARY TABLE event (id varchar(225) PRIMARY KEY, event_id varchar(225), kind integer NOT NULL, pubkey varchar(255) NOT NULL, content text NOT NULL, created_at bigint NOT NULL, tags jsonb NOT NULL, sig varchar(255) NOT NULL, d_tag varchar(512))');
            $connection->executeStatement('CREATE TEMPORARY TABLE banned_pubkey (pubkey varchar(64))');
            $connection->executeStatement('CREATE TEMPORARY TABLE app_user (npub varchar(255), roles jsonb)');
            $connection->executeStatement('CREATE TEMPORARY TABLE hidden_coordinate (coordinate varchar(512))');
            $connection->executeStatement('CREATE TEMPORARY TABLE deleted_event (id integer PRIMARY KEY, target_ref varchar(512), ref_type varchar(1), pubkey varchar(64), kind integer, deletion_event_id varchar(64), deletion_created_at bigint, reason text, recorded_at timestamp)');
            $owner = str_repeat('a', 64);
            $connection->executeStatement(
                "INSERT INTO event (id, kind, pubkey, content, created_at, tags, sig, d_tag)
                 SELECT lpad(to_hex(n), 64, '0'), CASE WHEN n % 2 = 1 THEN 30042 ELSE 30004 END,
                    :owner, '', 100000 - n,
                    jsonb_build_array(jsonb_build_array('d', 'item-' || n),
                        jsonb_build_array('title', 'Item ' || n),
                        jsonb_build_array('t', CASE WHEN n <= 5 THEN 'secret-facet-' || n ELSE 'public' END))
                        || CASE WHEN n = 5 THEN jsonb_build_array(jsonb_build_array('a', '34550:' || :owner || ':private')) ELSE '[]'::jsonb END,
                    '', 'item-' || n FROM generate_series(1, 1024) n",
                ['owner' => $owner],
            );
            $connection->executeStatement(
                "INSERT INTO event (id, kind, pubkey, content, created_at, tags, sig)
                 SELECT lpad(to_hex(5000 + n), 64, '0'), 0, lpad(to_hex(6000 + n), 64, '0'),
                    '{\"display_name\":\"Profile\"}', 1, '[]'::jsonb, '' FROM generate_series(1, 260) n",
            );
            $connection->insert('hidden_coordinate', ['coordinate' => '30042:' . $owner . ':item-1']);
            $connection->insert('hidden_coordinate', ['coordinate' => '30004:' . $owner . ':item-2']);
            foreach ([3, 4] as $id) {
                $connection->insert('deleted_event', [
                    'id' => $id,
                    'target_ref' => sprintf('%064x', $id),
                    'ref_type' => 'e',
                    'pubkey' => $owner,
                    'deletion_event_id' => str_repeat('f', 64),
                    'deletion_created_at' => 999999,
                    'recorded_at' => '2025-01-01 00:00:00',
                ]);
            }
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManagerForClass')->willReturn($em);
            $events = new EventRepository($registry);
            $deletions = new DeletedEventRepository($registry);
            $hidden = new HiddenCoordinateRepository($registry);
            $keys = new NostrKeyService();
            $authors = new ContentAuthorAccessPolicy(new UserEntityRepository($registry, $em), new BannedPubkeyRepository($registry), $keys, new NullLogger());
            $mutes = new UserMuteListService($events, new NullLogger());
            $drive = new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, new NullLogger());
            $user = new User();
            $user->setNpub(PublicKey::fromHex($owner)->toBech32());
            $security = $this->createMock(Security::class);
            $security->method('getUser')->willReturn($user);
            $access = new EventPreviewAccessPolicy(
                $authors, $deletions, $hidden, new RecordIdentityService(), $security, $mutes, $keys, new NullLogger(),
                new ArticleAccessService(new NostrIdentityService(
                    $this->createMock(Nip19DecoderInterface::class), $this->createMock(Nip19EncoderInterface::class),
                )),
            );
            $subscriptions = [];
            for ($n = 1; $n <= 260; ++$n) {
                $subscriptions[] = new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, sprintf('%064x', 6000 + $n));
            }
            $subscriptionRepository = $this->createMock(UpdateSubscriptionRepository::class);
            $subscriptionRepository->method('findActiveForUser')->willReturn($subscriptions);
            $context = null;
            $twig = $this->createMock(Environment::class);
            $twig->method('render')->willReturnCallback(static function (string $template, array $data) use (&$context): string {
                $context = $data;
                return 'ok';
            });
            $router = $this->createMock(RouterInterface::class);
            $router->method('generate')->willReturn('/action');
            $tokens = new TokenStorage();
            $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
            $container = new Container();
            $container->set('security.token_storage', $tokens);
            $container->set('router', $router);
            $container->set('twig', $twig);
            $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
            $controller->setContainer($container);

            $queries = [];
            $response = $controller->index(new Request(), $em, $events, $drive, $subscriptionRepository, $access);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1279, $context['totalCount']);
            self::assertSame(509, $context['sectionCounts']['drives']);
            self::assertSame(510, $context['sectionCounts']['reading_lists']);
            self::assertSame(260, $context['sectionCounts']['subscriptions']);
            self::assertStringNotContainsString('secret-facet', serialize($context));
            self::assertLessThanOrEqual(90, count($queries), '1,284 items must not produce per-item policy queries.');
            $countSql = static fn (string $needle): int => count(array_filter(
                $queries, static fn (string $sql): bool => str_contains(strtolower($sql), $needle),
            ));
            self::assertSame(3, count(array_filter($queries, static fn (string $sql): bool =>
                str_starts_with(strtolower(ltrim($sql)), 'with raw as')
            )), 'Subscription metadata is resolved in three batches.');
            self::assertLessThanOrEqual(11, $countSql('from banned_pubkey'));
            self::assertLessThanOrEqual(11, $countSql('from app_user'));
            self::assertLessThanOrEqual(11, $countSql('from deleted_event'));
            self::assertLessThanOrEqual(8, $countSql('from hidden_coordinate'));
            self::assertSame(1, count(array_filter($queries, static function (string $sql): bool {
                $sql = strtolower($sql);
                return str_starts_with(ltrim($sql), 'select ') && str_contains($sql, 'from event ') && str_contains($sql, 'order by')
                    && str_contains($sql, 'limit 1');
            })), 'The strict mute list is loaded exactly once.');

            // An absent signed d means the empty coordinate, even when the
            // denormalized projection contains a stale nonempty identifier.
            $connection->insert('deleted_event', [
                'id' => 9999,
                'target_ref' => '30004:' . $owner . ':',
                'ref_type' => 'a',
                'pubkey' => $owner,
                'deletion_event_id' => str_repeat('f', 64),
                'deletion_created_at' => 999999,
                'recorded_at' => '2025-01-01 00:00:00',
            ]);
            $stale = new Event();
            $stale->setId(sprintf('%064x', 9999));
            $stale->setKind(30004);
            $stale->setPubkey($owner);
            $stale->setCreatedAt(1);
            $stale->setTags([]);
            $stale->setDTag('stale');
            self::assertSame([$stale->getId()], $deletions->findSuppressedEventIds([$stale]));
        } finally {
            $em->close();
            $connection->close();
        }
    }
}
