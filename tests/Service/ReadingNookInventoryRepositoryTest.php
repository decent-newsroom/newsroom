<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Controller\Reader\ReadingNookController;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\RouterInterface;

final class ReadingNookInventoryRepositoryTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('CREATE TEMPORARY TABLE event AS SELECT * FROM public.event WHERE FALSE');
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testPostgresBatchesLatestRevisionsAndSignedDriveCoordinates(): void
    {
        $owner = str_repeat('a', 64);
        $this->connection->executeStatement(
            "INSERT INTO event (id, kind, pubkey, content, created_at, tags, d_tag, sig)
             SELECT lpad(to_hex(n), 64, '0'), 30004, :owner, '', 100000 - n,
                    jsonb_build_array(jsonb_build_array('d', 'list-' || n),
                        jsonb_build_array('t', CASE WHEN n > 400 THEN 'older' ELSE 'newer' END)),
                    'list-' || n, ''
             FROM generate_series(1, 430) n",
            ['owner' => $owner],
        );
        foreach ([
            [1000, 30004, 100001, [['d', 'list-410']], 'list-410', ''],
            [1001, 30004, 100000 - 420, [['d', 'list-420']], 'list-420', ''],
            [1002, 30042, 100002, [['d', 'drive'], ['d', 'other']], null, ''],
            [1003, 30042, 100003, [['d', 'drive']], null, 'private'],
            [1004, 30042, 100004, [['d', 'other']], null, ''],
        ] as [$id, $kind, $created, $tags, $dTag, $content]) {
            $this->connection->insert('event', [
                'id' => sprintf('%064x', $id), 'kind' => $kind, 'pubkey' => $owner,
                'created_at' => $created, 'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                'd_tag' => $dTag, 'content' => $content, 'sig' => '',
            ]);
        }
        $repository = self::getContainer()->get(EventRepository::class);
        $events = iterator_to_array($repository->iterateReadingNookEvents($owner, [30004], true), false);
        self::assertCount(432, $events);
        $ids = array_map(static fn (Event $event): string => $event->getId(), $events);
        self::assertContains(sprintf('%064x', 1000), $ids);
        self::assertNotContains(sprintf('%064x', 410), $ids);
        self::assertContains(sprintf('%064x', 420), $ids);
        self::assertNotContains(sprintf('%064x', 1001), $ids);
        self::assertContains(sprintf('%064x', 1003), $ids);
        self::assertContains(sprintf('%064x', 1004), $ids);
        self::assertNotContains(sprintf('%064x', 1002), $ids);
        self::assertSame(sprintf('%064x', 430), end($ids));
        self::assertCount(430, iterator_to_array($repository->iterateReadingNookEvents($owner, [30004], false)));

        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/action');
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);
        $buildEntries = new \ReflectionMethod($controller, 'buildEntriesFromEvents');
        $paginate = new \ReflectionMethod($controller, 'paginateInventory');
        $items = (static function () use ($repository, $owner, $buildEntries, $controller): \Generator {
            foreach ($repository->iterateReadingNookEvents($owner, [30004], false) as $event) {
                yield $buildEntries->invoke($controller, [$event])[0];
            }
        })();
        $inventory = $paginate->invoke($controller, $items, [], [
            'q' => 'list-4', 'section' => 'reading_lists', 'timespan' => 'all', 'tag' => 'older',
        ], 2);
        self::assertSame(430, $inventory['totalCount']);
        self::assertSame(29, $inventory['filteredCount']);
        self::assertSame(['list-427', 'list-428', 'list-429', 'list-430'], array_column($inventory['items'], 'title'));
        self::assertContains('newer', $inventory['allTags']);

        $stream = $repository->iterateReadingNookEvents($owner, [30004], false);
        $stream->current();
        unset($stream);
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame(430, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event WHERE kind = 30004') - 2);
    }

    public function testPostgresLatestMetadataBatchUsesLowerIdOnTie(): void
    {
        $author = str_repeat('b', 64);
        foreach ([
            [str_repeat('f', 64), 100],
            [str_repeat('2', 64), 100],
            [str_repeat('1', 64), 99],
        ] as [$id, $created]) {
            $this->connection->insert('event', [
                'id' => $id,
                'kind' => 0,
                'pubkey' => $author,
                'content' => '{}',
                'created_at' => $created,
                'tags' => '[]',
                'sig' => '',
            ]);
        }

        $repository = self::getContainer()->get(EventRepository::class);
        self::assertSame(str_repeat('2', 64), $repository->findLatestMetadataByPubkeys([$author])[$author]->getId());
    }

    public function testPostgresSubscriptionBatchPreservesSignedIdentityAndMetadata(): void
    {
        $author = str_repeat('b', 64);
        foreach ([
            [1, 0, 100, []],
            [2, 0, 100, []],
            [3, 30040, 100, [['d', 'book']]],
            [4, 30040, 100, [['d', 'book']]],
            [5, 30040, 200, [['d', 'other'], ['d', 'book']]],
            [6, 30040, 300, [['d', ['malformed']], ['d', 'book']]],
        ] as [$id, $kind, $created, $tags]) {
            $this->connection->insert('event', [
                'id' => sprintf('%064x', $id), 'kind' => $kind, 'pubkey' => $author,
                'content' => '{"name":"Local"}', 'created_at' => $created,
                'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
                'd_tag' => $kind === 30040 ? 'stale' : null, 'sig' => '',
            ]);
        }
        $repository = self::getContainer()->get(EventRepository::class);
        $targets = $repository->findReadingNookSubscriptionTargets([
            ['kind' => 0, 'pubkey' => $author, 'dTag' => null],
            ['kind' => 30040, 'pubkey' => $author, 'dTag' => 'book'],
        ]);
        self::assertSame(sprintf('%064x', 1), $targets['0:' . $author]->getId());
        self::assertSame(sprintf('%064x', 3), $targets['30040:' . $author . ':book']->getId());
        self::assertSame('{"name":"Local"}', $targets['0:' . $author]->getContent());
    }
}
