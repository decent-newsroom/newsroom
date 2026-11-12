<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class EventPublicationIndexTargetRepositoryTest extends TestCase
{
    public function testOneBatchedQueryReturnsCurrentCoordinatesAndExactPins(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated repository query requires pdo_sqlite.');
        }

        $queries = [];
        $logger = new class(static function (string $sql) use (&$queries): void { $queries[] = $sql; }) extends \Psr\Log\AbstractLogger {
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
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $entityManager = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $connectionConfig),
            $config,
        );
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Event::class)]);

        $author = str_repeat('a', 64);
        $old = $this->event(str_repeat('b', 64), $author, 'index', 100);
        $currentTie = $this->event(str_repeat('c', 64), $author, 'index', 200);
        $currentHigherId = $this->event(str_repeat('d', 64), $author, 'index', 200);
        $wrongKindPin = $this->event(str_repeat('e', 64), $author, 'chapter', 300, 30041);
        $duplicateDTagDecoy = $this->event(str_repeat('f', 64), $author, 'index', 400);
        $duplicateDTagDecoy->setTags([['d', 'other'], ['d', 'index']]);
        foreach ([$old, $currentTie, $currentHigherId, $wrongKindPin, $duplicateDTagDecoy] as $event) {
            $entityManager->persist($event);
        }
        $entityManager->flush();
        $entityManager->clear();
        $queries = [];

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($entityManager);
        $repository = new EventRepository($registry);
        $resolved = $repository->findPublicationIndexTargets(
            ['30040:' . $author . ':index'],
            [str_repeat('b', 64), str_repeat('e', 64)],
        );

        self::assertCount(1, $queries, 'All visible address and exact-ID targets use one local query.');
        self::assertSame(str_repeat('c', 64), $resolved['coordinates']['30040:' . $author . ':index']->getId());
        self::assertSame(str_repeat('b', 64), $resolved['eventIds'][str_repeat('b', 64)]->getId());
        self::assertSame(30041, $resolved['eventIds'][str_repeat('e', 64)]->getKind());

        $queries = [];
        $workspace = $repository->findWorkspacePreviewTargets(
            ['30040:' . $author . ':index', '30041:' . $author . ':chapter', '30041:' . $author . ':missing'],
            [str_repeat('b', 64), str_repeat('b', 64)],
        );
        self::assertCount(1, $queries);
        self::assertCount(2, $workspace['coordinates']);
        self::assertSame(str_repeat('c', 64), $workspace['coordinates']['30040:' . $author . ':index']->getId());
        self::assertSame(str_repeat('e', 64), $workspace['coordinates']['30041:' . $author . ':chapter']->getId());
        self::assertSame(str_repeat('b', 64), $workspace['eventIds'][str_repeat('b', 64)]->getId());
        self::assertStringNotContainsString('article', strtolower($queries[0]), 'No Article projection is queried or required.');
        self::assertStringNotContainsString('e.content', $queries[0]);
        self::assertStringNotContainsString('e.*', $queries[0]);
        self::assertSame('', $workspace['eventIds'][str_repeat('b', 64)]->getContent());
        $queries = [];
        self::assertSame(['coordinates' => [], 'eventIds' => []], $repository->findWorkspacePreviewTargets([], []));
        self::assertCount(0, $queries);

        $entityManager->close();
    }

    public function testWorkspaceLookupRejectsMoreThanOnePageBeforeQuerying(): void
    {
        $repository = new EventRepository($this->createMock(ManagerRegistry::class));
        $this->expectException(\InvalidArgumentException::class);
        $repository->findWorkspacePreviewTargets([], array_fill(0, 51, str_repeat('a', 64)));
    }

    private function event(string $id, string $pubkey, string $dTag, int $createdAt, int $kind = 30040): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setDTag($dTag);
        $event->setCreatedAt($createdAt);
        $event->setTags([['d', $dTag]]);
        $event->setContent('');
        $event->setSig('');

        return $event;
    }
}
