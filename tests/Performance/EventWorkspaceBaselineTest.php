<?php

declare(strict_types=1);

namespace App\Tests\Performance;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\User;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\BannedPubkeyRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocDiscovery;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\RelayRegistry;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Error\Error as TwigError;

/**
 * Explicitly opted-in, synthetic, connection-local PostgreSQL benchmark.
 * It never reads application tables or sends a real Messenger message.
 */
final class EventWorkspaceBaselineTest extends KernelTestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SOURCE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const WARMUPS = 5;
    private const SAMPLES = 30;

    public function testSyntheticTocFragmentBaseline(): void
    {
        if (getenv('WORKSPACE_BASELINE') !== '1') {
            self::markTestSkipped('Run explicitly with WORKSPACE_BASELINE=1; see the workspace baseline documentation.');
        }

        $this->withSafeInfrastructureDiagnostics(
            fn (string &$phase) => $this->runFixture($phase),
        );
    }

    public function testAssertionFailuresAreNotMasked(): void
    {
        $failure = new \PHPUnit\Framework\AssertionFailedError('Expected assertion detail');
        try {
            $this->withSafeInfrastructureDiagnostics(static function (string &$phase) use ($failure): void {
                throw $failure;
            });
            self::fail('Expected the original assertion failure.');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testUnexpectedErrorsAreNotMasked(): void
    {
        $failure = new \LogicException('Expected programming error');
        try {
            $this->withSafeInfrastructureDiagnostics(static function (string &$phase) use ($failure): void {
                throw $failure;
            });
            self::fail('Expected the original programming error.');
        } catch (\LogicException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testTwigWrappedAssertionsAreNotMasked(): void
    {
        $failure = new \PHPUnit\Framework\AssertionFailedError('Expected assertion detail');
        try {
            $this->withSafeInfrastructureDiagnostics(static function (string &$phase) use ($failure): void {
                throw new TwigError('Template failed', -1, null, $failure);
            });
            self::fail('Expected the original assertion failure.');
        } catch (\PHPUnit\Framework\AssertionFailedError $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testInfrastructureErrorsHaveSafeActionableDiagnostics(): void
    {
        foreach ([
            new \Doctrine\DBAL\Exception\InvalidArgumentException('sensitive database detail'),
            new \PDOException('sensitive connection detail'),
            new TwigError('sensitive template detail'),
            new \Symfony\Component\DependencyInjection\Exception\RuntimeException('sensitive framework detail'),
        ] as $failure) {
            try {
                $this->withSafeInfrastructureDiagnostics(static function (string &$phase) use ($failure): void {
                    $phase = 'fixture setup';
                    throw $failure;
                });
                self::fail('Expected a sanitized infrastructure error.');
            } catch (\RuntimeException $caught) {
                self::assertStringContainsString($failure::class, $caught->getMessage());
                self::assertStringContainsString('fixture setup', $caught->getMessage());
                self::assertStringContainsString('Check ', $caught->getMessage());
                self::assertStringNotContainsString('sensitive', $caught->getMessage());
                self::assertNull($caught->getPrevious());
            }
        }
    }

    public function testConnectionClosesWhenFixtureSetupFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('close');
        $failure = new \RuntimeException('fixture setup failure');
        try {
            $this->withConnection($connection, static function () use ($failure): void {
                throw $failure;
            });
            self::fail('Expected the setup failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    /** @param callable(string&): void $run */
    private function withSafeInfrastructureDiagnostics(callable $run): void
    {
        $phase = 'kernel bootstrap';
        try {
            $run($phase);
        } catch (DbalException|\PDOException $error) {
            throw new \RuntimeException(sprintf(
                'Workspace baseline database failure (%s) during %s. Check PostgreSQL availability, DATABASE_URL, and temporary-table privileges.',
                $error::class,
                $phase,
            ));
        } catch (TwigError $error) {
            for ($previous = $error->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
                if ($previous instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $previous;
                }
            }
            throw new \RuntimeException(sprintf(
                'Workspace baseline template failure (%s) during %s. Check the TOC Twig template and component configuration.',
                $error::class,
                $phase,
            ));
        } catch (\Symfony\Component\DependencyInjection\Exception\ExceptionInterface
            | \Symfony\Component\Config\Exception\ExceptionInterface
            | \Symfony\Component\Filesystem\Exception\ExceptionInterface $error) {
            throw new \RuntimeException(sprintf(
                'Workspace baseline framework failure (%s) during %s. Check the test container configuration and cache permissions.',
                $error::class,
                $phase,
            ));
        }
    }

    private function runFixture(string &$phase): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $counter = new class extends AbstractLogger {
            public bool $enabled = false;
            public int $count = 0;

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($this->enabled && isset($context['sql'])) {
                    ++$this->count;
                }
            }
        };
        $dbal = new DbalConfiguration();
        $dbal->setMiddlewares([new Middleware($counter)]);
        $phase = 'database configuration';
        $url = getenv('DATABASE_URL');
        self::assertIsString($url);
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        self::assertTrue(($params['driver'] ?? null) === 'pdo_pgsql', 'DATABASE_URL must select PostgreSQL.');
        $connection = DriverManager::getConnection($params, $dbal);
        $this->withConnection($connection, function () use ($connection, $counter, $container, &$phase): void {
            $this->runConnectedFixture($connection, $counter, $container, $phase);
        });
    }

    private function withConnection(Connection $connection, callable $run): void
    {
        try {
            $run();
        } finally {
            $connection->close();
        }
    }

    private function runConnectedFixture(Connection $connection, AbstractLogger $counter, \Psr\Container\ContainerInterface $container, string &$phase): void
    {
        $phase = 'database connection check';
        $counter->enabled = true;
        $connection->executeQuery('SELECT ?', ['synthetic-only'])->fetchOne();
        $connection->executeQuery('SELECT 1')->fetchOne();
        $counter->enabled = false;
        self::assertSame(2, $counter->count, 'Count prepared and direct execution once each.');
        self::assertSame(['enabled' => false, 'count' => 2], get_object_vars($counter));
        $phase = 'temporary-table setup';
        $connection->executeStatement('CREATE TEMPORARY TABLE event (id varchar(225) PRIMARY KEY, event_id varchar(225), kind integer NOT NULL, pubkey varchar(255) NOT NULL, content text NOT NULL, created_at bigint NOT NULL, tags jsonb NOT NULL, sig varchar(255) NOT NULL, d_tag varchar(512))');
        $connection->executeStatement('CREATE TEMPORARY TABLE deleted_event (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, target_ref varchar(512), ref_type varchar(1), pubkey varchar(64), kind integer, deletion_event_id varchar(64), deletion_created_at bigint, reason text, recorded_at timestamp)');
        $connection->executeStatement('CREATE TEMPORARY TABLE hidden_coordinate (id integer GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, coordinate varchar(512))');
        $connection->executeStatement('CREATE TEMPORARY TABLE banned_pubkey (pubkey varchar(64) PRIMARY KEY, reason text, added_at timestamp, added_by varchar(255))');
        $connection->executeStatement('CREATE TEMPORARY TABLE app_user (npub varchar(255), roles jsonb)');

        $phase = 'fixture setup';
        $orm = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Entity'], true);
        $orm->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        if (PHP_VERSION_ID >= 80400) {
            $orm->enableNativeLazyObjects(true);
        }
        $em = new EntityManager($connection, $orm);
        try {
            $this->runEntityFixture($em, $connection, $counter, $container, $phase);
        } finally {
            $em->close();
        }
    }

    private function runEntityFixture(EntityManager $em, Connection $connection, AbstractLogger $counter, \Psr\Container\ContainerInterface $container, string &$phase): void
    {
        $em->getClassMetadata(Event::class)->setCustomRepositoryClass(EntityRepository::class);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $events = new EventRepository($registry);
        $deletions = new DeletedEventRepository($registry);
        $hidden = new HiddenCoordinateRepository($registry);
        $authors = new ContentAuthorAccessPolicy(
            new UserEntityRepository($registry, $em),
            new BannedPubkeyRepository($registry),
            new NostrKeyService(),
            new NullLogger(),
        );
        $identity = new RecordIdentityService();
        $presentations = new EventPresentationFactory($identity, new NostrNip19Service());
        $toc = new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identity), $presentations, $events, $deletions,
            $authors, $hidden, new PublicationTocTraversalSigner('synthetic-baseline-secret'),
        );

        $tags = [['d', 'root']];
        for ($i = 0; $i < 40; ++$i) {
            $tags[] = ['a', '30040:' . self::OWNER . ':child-' . $i];
            if ($i < 20) {
                $this->insertEvent($connection, str_pad(dechex($i + 1), 64, '0', STR_PAD_LEFT), 30040, 'child-' . $i, [['d', 'child-' . $i], ['title', 'Synthetic child']]);
            }
        }
        for ($i = 0; $i < 5; ++$i) {
            $id = str_pad(dechex($i + 100), 64, '0', STR_PAD_LEFT);
            $tags[] = ['a', '30041:' . self::OWNER . ':chapter-' . $i, '', $id];
            $this->insertEvent($connection, $id, 30041, 'chapter-' . $i, [['d', 'chapter-' . $i], ['title', 'Synthetic chapter']]);
        }
        for ($i = 0; $i < 5; ++$i) {
            $tags[] = ['a', 'malformed'];
        }
        $this->insertEvent($connection, self::SOURCE, 30040, 'root', $tags);

        // Exercise the new drive queries against PostgreSQL without touching
        // application rows or including these checks in fragment timings.
        $olderDirectory = str_repeat('c', 64);
        $this->insertEvent($connection, $olderDirectory, 30045, 'baseline-dir', [['d', 'baseline-dir']], '', 100);
        $this->insertEvent($connection, str_repeat('d', 64), 30045, 'baseline-dir', [['d', 'baseline-dir']], 'invalid', 200);
        $this->insertEvent($connection, str_repeat('1', 64), 30042, 'drive-one', [['d', 'drive-one']], '', 100);
        $this->insertEvent($connection, str_repeat('2', 64), 30042, 'drive-one', [['d', 'drive-one']], '', 200);
        $this->insertEvent($connection, str_repeat('3', 64), 30042, 'drive-two', [['d', 'drive-two']], '', 150);
        $this->insertEvent($connection, str_repeat('4', 64), 30042, 'invalid-drive', [['d', 'invalid-drive']], '', 100);
        $this->insertEvent($connection, str_repeat('5', 64), 30042, 'invalid-drive', [['d', 'invalid-drive']], 'invalid', 200);
        $coordinate = '30045:' . self::OWNER . ':baseline-dir';
        $directories = $events->findWorkspaceDirectoryTargets([$coordinate], [$olderDirectory]);
        self::assertArrayNotHasKey($coordinate, $directories['coordinates']);
        self::assertSame($olderDirectory, $directories['eventIds'][$olderDirectory]->getId());
        self::assertSame(
            [str_repeat('2', 64), str_repeat('3', 64)],
            array_map(static fn (Event $drive): string => $drive->getId(), $events->findWorkspaceCurrentDrives(self::OWNER)),
        );
        $em->clear();

        $mutes = $this->createStub(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn([]);
        $controller = new ReadingNookController($this->createStub(RedisCacheService::class));
        $controllerContainer = new Container();
        $storage = new TokenStorage();
        $user = new User();
        $user->setNpub(PublicKey::fromHex(self::OWNER)->toBech32());
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $controllerContainer->set('security.token_storage', $storage);
        $controllerContainer->set('twig', $container->get('twig'));
        $controller->setContainer($controllerContainer);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = $container->get('request_stack');
        $stack->push($request);

        try {
            $phase = 'fragment sampling';
            $times = [];
            $queries = [];
            for ($i = 0; $i < self::WARMUPS + self::SAMPLES; ++$i) {
                $em->clear();
                $counter->count = 0;
                $counter->enabled = true;
                $start = hrtime(true);
                $response = $controller->publicationToc(self::SOURCE, $request, $em, $toc, $deletions, $authors, $mutes);
                $elapsed = (hrtime(true) - $start) / 1_000_000;
                $counter->enabled = false;
                self::assertSame(200, $response->getStatusCode());
                self::assertTrue(str_contains($response->getContent(), '<turbo-frame'));
                if ($i >= self::WARMUPS) {
                    $times[] = $elapsed;
                    $queries[] = $counter->count;
                }
            }

            $phase = 'resolution and discovery checks';
            $em->clear();
            $source = $em->getRepository(Event::class)->find(self::SOURCE);
            $page = $toc->fromAuthorizedEvent($source);
            $statuses = ['available' => 0, 'unavailable' => 0, 'invalid' => 0, 'other' => 0];
            foreach ($page['entries'] as $entry) {
                $status = $entry['targetResolution'] ?? ($entry['reference']->status->value === 'invalid' ? 'invalid' : 'other');
                ++$statuses[array_key_exists($status, $statuses) ? $status : 'other'];
            }
            self::assertSame(['available' => 25, 'unavailable' => 20, 'invalid' => 5, 'other' => 0], $statuses);
            self::assertCount(self::SAMPLES, $times);
            self::assertGreaterThan(0, min($queries));
            sort($times);
            sort($queries);
            $queue = $this->discoveryCounts($presentations, $source);
            self::assertSame(['queued_fetch_count' => 1, 'loading_count' => 2], $queue);

            // Only fixed labels and aggregate numbers leave the fixture.
            fwrite(STDOUT, "\nWORKSPACE_BASELINE " . json_encode([
                'warmups' => self::WARMUPS,
                'samples' => self::SAMPLES,
                'query_count_min' => min($queries),
                'query_count_max' => max($queries),
                'fragment_ms_median' => round(($times[14] + $times[15]) / 2, 3),
                'fragment_ms_p95' => round($times[(int) ceil(0.95 * count($times)) - 1], 3),
                'resolution_counts_per_fragment' => $statuses,
                'discovery_simulation' => $queue,
            ], JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $stack->pop();
        }
    }

    private function insertEvent(Connection $db, string $id, int $kind, string $dTag, array $tags, string $content = '', int $createdAt = 123): void
    {
        $db->insert('event', [
            'id' => $id, 'kind' => $kind, 'pubkey' => self::OWNER, 'content' => $content,
            'created_at' => $createdAt, 'tags' => json_encode($tags, JSON_THROW_ON_ERROR), 'sig' => '', 'd_tag' => $dTag,
        ]);
    }

    /** @return array{queued_fetch_count: int, loading_count: int} */
    private function discoveryCounts(EventPresentationFactory $presentations, Event $source): array
    {
        $reference = $presentations->fromAuthorizedEvent($source)->references[20];
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::exactly(2))->method('eval')->willReturnOnConsecutiveCalls([1, 60], [0, 60]);
        $queued = 0;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use (&$queued): Envelope {
            self::assertInstanceOf(FetchEventFromRelaysMessage::class, $message);
            ++$queued;
            return new Envelope($message, $stamps);
        });
        $relays = $this->createStub(RelayRegistry::class);
        $relays->method('getContentRelays')->willReturn([]);
        $discovery = new PublicationTocDiscovery($redis, $bus, $relays, new NullLogger(), 'fixture');
        $loading = 0;
        for ($i = 0; $i < 2; ++$i) {
            $loading += (int) ($discovery->request(self::OWNER, $reference)['status'] === 'loading');
        }
        return ['queued_fetch_count' => $queued, 'loading_count' => $loading];
    }
}
