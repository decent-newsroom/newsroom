<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Article;
use App\Enum\KindsEnum;
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

    public function testLegacyLookupIgnoresMalformedTagContainers(): void
    {
        $this->insertEvent('a', 300, ['d' => 'index'], null);
        $this->insertEvent('b', 200, ['not-a-tag', ['d', 'index']], null);
        $repository = self::getContainer()->get(EventRepository::class);
        $coordinate = '30040:' . self::AUTHOR . ':index';

        self::assertSame(str_repeat('b', 64), $repository->findByNaddr(30040, self::AUTHOR, 'index')?->getId());
        self::assertSame(
            str_repeat('b', 64),
            $repository->findHighlightSourceTargets([$coordinate], [])['coordinates'][$coordinate]->getId(),
        );
    }

    public function testGuestHighlightBatchMatchesIndexedAndLegacyPreviewSelection(): void
    {
        $this->insertEvent('a', 100, [['d', 'index']], null);
        $this->insertEvent('c', 200, [['d', 'index']], null);
        $this->insertEvent('d', 200, [['d', 'index']], null);
        $this->insertEvent('b', 500, [['d', 42], ['d', 'index']], null);
        $this->insertEvent('e', 400, [['d', 'other'], ['d', 'index']], 'index');
        $repository = self::getContainer()->get(EventRepository::class);
        $coordinate = '30040:' . self::AUTHOR . ':index';

        $indexed = $repository->findHighlightSourceTargets([$coordinate], []);
        self::assertSame(str_repeat('e', 64), $indexed['coordinates'][$coordinate]->getId());
        self::assertSame(
            $repository->findByNaddr(30040, self::AUTHOR, 'index')->getId(),
            $indexed['coordinates'][$coordinate]->getId(),
        );

        $this->connection->delete('event', ['id' => str_repeat('e', 64)]);
        $legacy = $repository->findHighlightSourceTargets([$coordinate], []);
        self::assertSame(str_repeat('c', 64), $legacy['coordinates'][$coordinate]->getId());
        self::assertSame(
            $repository->findByNaddr(30040, self::AUTHOR, 'index')->getId(),
            $legacy['coordinates'][$coordinate]->getId(),
        );
        self::assertArrayNotHasKey(
            '30040:' . self::AUTHOR . ':42',
            $repository->findHighlightSourceTargets(['30040:' . self::AUTHOR . ':42'], [])['coordinates'],
        );
    }

    public function testGuestHighlightLegacyLookupRequiresAnExplicitEmptyDTag(): void
    {
        $this->insertEvent('a', 200, [['title', 'No identifier']], null);
        $repository = self::getContainer()->get(EventRepository::class);
        $coordinate = '30040:' . self::AUTHOR . ':';

        self::assertNull($repository->findByNaddr(30040, self::AUTHOR, ''));
        self::assertArrayNotHasKey($coordinate, $repository->findHighlightSourceTargets([$coordinate], [])['coordinates']);

        $this->insertEvent('b', 100, [['d', '']], null);
        self::assertSame(str_repeat('b', 64), $repository->findByNaddr(30040, self::AUTHOR, '')->getId());
        self::assertSame(
            str_repeat('b', 64),
            $repository->findHighlightSourceTargets([$coordinate], [])['coordinates'][$coordinate]->getId(),
        );
    }

    public function testGuestHighlightExactLookupUsesTheSameCaseSensitiveIdAsThePreview(): void
    {
        $this->insertEvent('b', 100, [], null);
        $this->insertEvent('B', 200, [], null);
        $this->insertEvent('C', 300, [], null);
        $repository = self::getContainer()->get(EventRepository::class);
        $lower = str_repeat('b', 64);
        $upperOnly = str_repeat('c', 64);

        $targets = $repository->findHighlightSourceTargets([], [strtoupper($lower), $upperOnly]);

        self::assertSame($lower, $targets['eventIds'][$lower]->getId());
        self::assertArrayNotHasKey($upperOnly, $targets['eventIds']);
        self::assertNull($repository->findById($upperOnly));
    }

    public function testGuestHighlightArticleBatchMatchesExactStoredIds(): void
    {
        foreach ([
            [1, str_repeat('d', 64), 30023],
            [2, str_repeat('d', 64), 30023],
            [3, str_repeat('f', 64), 30024],
            [4, str_repeat('E', 64), 30023],
        ] as [$id, $eventId, $kind]) {
            $this->connection->insert('article', [
                'id' => $id,
                'event_id' => $eventId,
                'kind' => $kind,
                'pubkey' => self::AUTHOR,
                'slug' => 'article',
                'created_at' => '2026-10-01 12:00:00',
                'essayist_exclusive' => 'false',
            ]);
        }

        $articles = self::getContainer()->get(ArticleRepository::class)->findHighlightSourcesByEventIds([
            str_repeat('D', 64), str_repeat('d', 64), str_repeat('f', 64),
            str_repeat('e', 64), str_repeat('E', 64),
        ]);
        self::assertSame([str_repeat('d', 64), str_repeat('f', 64), str_repeat('E', 64)], array_keys($articles));
        self::assertSame([1, 2], array_map(static fn (Article $article): ?int => $article->getId(), $articles[str_repeat('d', 64)]));
        self::assertSame(KindsEnum::LONGFORM_DRAFT, $articles[str_repeat('f', 64)][0]->getKind());
        self::assertArrayNotHasKey(str_repeat('e', 64), $articles);
        self::assertArrayNotHasKey(str_repeat('D', 64), $articles);
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
