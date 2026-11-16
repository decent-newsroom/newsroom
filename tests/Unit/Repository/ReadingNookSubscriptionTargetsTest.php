<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ReadingNookSubscriptionTargetsTest extends TestCase
{
    public function testOneQueryResolvesSignedCoordinatesAndMetadataWithDeterministicTies(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Requires pdo_sqlite.');
        }
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
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $dbal), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Event::class)]);
        $author = str_repeat('a', 64);
        foreach ([
            [1, 0, 100, []],
            [2, 0, 100, []],
            [3, 30040, 100, [['d', 'book']]],
            [4, 30040, 100, [['d', 'book']]],
            [5, 30040, 200, [['d', 'different'], ['d', 'book']]],
            [6, 30040, 300, [['d', ['malformed']], ['d', 'book']]],
        ] as [$id, $kind, $createdAt, $tags]) {
            $event = new Event();
            $event->setId(sprintf('%064x', $id));
            $event->setKind($kind);
            $event->setPubkey($author);
            $event->setCreatedAt($createdAt);
            $event->setTags($tags);
            $event->setDTag($kind === 30040 ? 'stale' : null);
            $event->setContent('{"name":"Name"}');
            $event->setSig('');
            $em->persist($event);
        }
        $em->flush();
        $em->clear();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($em);
        $repository = new EventRepository($registry);
        $queries = [];
        $result = $repository->findReadingNookSubscriptionTargets([
            ['kind' => 0, 'pubkey' => $author, 'dTag' => null],
            ['kind' => 30040, 'pubkey' => $author, 'dTag' => 'book'],
            ['kind' => 30040, 'pubkey' => $author, 'dTag' => 'book'],
            ['kind' => 30040, 'pubkey' => $author, 'dTag' => 'missing'],
        ]);
        self::assertCount(1, $queries);
        self::assertCount(2, $result);
        self::assertSame(sprintf('%064x', 1), $result['0:' . $author]->getId());
        self::assertSame(sprintf('%064x', 3), $result['30040:' . $author . ':book']->getId());
        self::assertSame('{"name":"Name"}', $result['0:' . $author]->getContent());
        $newest = new Event();
        $newest->setId(sprintf('%064x', 7));
        $newest->setKind(0);
        $newest->setPubkey($author);
        $newest->setCreatedAt(200);
        $newest->setTags([]);
        $newest->setContent('{"name":"Malformed"}');
        $newest->setSig('');
        $em->persist($newest);
        $em->flush();
        $em->getConnection()->executeStatement("UPDATE event SET tags = '{}' WHERE id = ?", [$newest->getId()]);
        $result = $repository->findReadingNookSubscriptionTargets([
            ['kind' => 0, 'pubkey' => $author, 'dTag' => null],
        ]);
        self::assertSame([], $result, 'A malformed latest profile cannot reveal an older revision.');
        $em->close();
    }
}
