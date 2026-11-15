<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PostgreSQL coverage for the actual ordering and first-d JSONB predicates.
 * Temporary tables shadow application tables and are rolled back after each test.
 */
final class ApiPreviewRevisionRepositoryTest extends KernelTestCase
{
    private Connection $connection;
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('CREATE TEMPORARY TABLE article AS SELECT * FROM public.article WHERE FALSE');
        $this->connection->executeStatement('CREATE TEMPORARY TABLE event AS SELECT * FROM public.event WHERE FALSE');
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testArticlePreviewSelectsNewestRevisionThenLowestEventId(): void
    {
        foreach ([
            [1, 'a', '2026-10-01 12:00:00'],
            [2, 'd', '2026-10-02 12:00:00'],
            [3, 'c', '2026-10-02 12:00:00'],
        ] as [$id, $eventId, $createdAt]) {
            $this->connection->insert('article', [
                'id' => $id,
                'event_id' => str_repeat($eventId, 64),
                'kind' => 30023,
                'pubkey' => self::AUTHOR,
                'slug' => 'article',
                'created_at' => $createdAt,
                'essayist_exclusive' => 'false',
            ]);
        }

        $article = self::getContainer()->get(ArticleRepository::class)->findOneBy(
            ['slug' => 'article', 'pubkey' => self::AUTHOR],
            ['createdAt' => 'DESC', 'eventId' => 'ASC'],
        );
        self::assertInstanceOf(Article::class, $article);
        self::assertSame(str_repeat('c', 64), $article->getEventId());
    }

    /** @dataProvider lookupBranches */
    public function testNaddrSelectsNewestRevisionThenLowestId(bool $legacy): void
    {
        $this->insertEvent('a', 100, [['d', 'index']], $legacy ? null : 'index');
        $this->insertEvent('d', 200, [['d', 'index']], $legacy ? null : 'index');
        $this->insertEvent('c', 200, [['d', 'index']], $legacy ? null : 'index');

        $event = self::getContainer()->get(EventRepository::class)->findByNaddr(30040, self::AUTHOR, 'index');

        self::assertNotNull($event);
        self::assertSame(str_repeat('c', 64), $event->getId());
    }

    public static function lookupBranches(): iterable
    {
        yield 'indexed d_tag' => [false];
        yield 'legacy JSONB fallback' => [true];
    }

    public function testLegacyLookupOnlyMatchesFirstDTag(): void
    {
        $this->insertEvent('a', 300, [['d', 'other'], ['d', 'index']], null);
        $this->insertEvent('b', 400, [['d', 42], ['d', 'index']], null);
        $this->insertEvent('c', 100, [['title', 'valid'], ['d', 'index'], ['d', 'other']], null);

        $repository = self::getContainer()->get(EventRepository::class);
        self::assertSame(str_repeat('c', 64), $repository->findByNaddr(30040, self::AUTHOR, 'index')?->getId());
        self::assertNull($repository->findByNaddr(30040, self::AUTHOR, '42'), 'Non-string first d is not an identifier.');
    }

    private function insertEvent(string $id, int $createdAt, array $tags, ?string $dTag): void
    {
        $this->connection->insert('event', [
            'id' => str_repeat($id, 64),
            'kind' => 30040,
            'pubkey' => self::AUTHOR,
            'content' => '',
            'created_at' => $createdAt,
            'tags' => json_encode($tags, JSON_THROW_ON_ERROR),
            'd_tag' => $dTag,
            'sig' => '',
        ]);
    }
}
