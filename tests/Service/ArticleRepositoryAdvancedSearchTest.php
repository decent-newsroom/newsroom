<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\SearchFilters;
use App\Repository\ArticleRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArticleRepositoryAdvancedSearchTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'CREATE TEMPORARY TABLE article AS SELECT * FROM public.article WHERE FALSE'
        );
        // Production stores whole seconds. Higher precision here proves the query
        // includes the full final day if stored timestamps ever gain microseconds.
        $this->connection->executeStatement(
            'ALTER TABLE article ALTER COLUMN created_at TYPE timestamp(6) without time zone'
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testDateRangeIncludesBothSelectedCalendarDaysInDoctrineAndTagQueries(): void
    {
        $rows = [
            [1, 'before', '2026-08-31 23:59:59'],
            [2, 'start', '2026-09-01 00:00:00'],
            [3, 'end', '2026-09-25 23:59:59'],
            [4, 'last-microsecond', '2026-09-25 23:59:59.999999'],
            [5, 'after', '2026-09-26 00:00:00'],
        ];

        foreach ($rows as [$id, $slug, $createdAt]) {
            $this->connection->executeStatement(
                'INSERT INTO article (id, slug, pubkey, created_at, topics, essayist_exclusive)
                 VALUES (:id, :slug, :pubkey, :createdAt, :topics, FALSE)',
                [
                    'id' => $id,
                    'slug' => $slug,
                    'pubkey' => str_repeat('b', 64),
                    'createdAt' => $createdAt,
                    'topics' => '["nostr"]',
                ],
            );
        }

        /** @var ArticleRepository $repository */
        $repository = self::getContainer()->get(ArticleRepository::class);
        $dates = new SearchFilters(dateFrom: '2026-09-01', dateTo: '2026-09-25', sortBy: 'oldest');

        self::assertSame(
            ['start', 'end', 'last-microsecond'],
            array_map(static fn ($article): ?string => $article->getSlug(), $repository->advancedSearch('', $dates)),
        );

        $datesAndTag = new SearchFilters(
            dateFrom: '2026-09-01',
            dateTo: '2026-09-25',
            tags: 'nostr',
            sortBy: 'oldest',
        );

        self::assertSame(
            ['start', 'end', 'last-microsecond'],
            array_map(static fn ($article): ?string => $article->getSlug(), $repository->advancedSearch('', $datesAndTag)),
        );
    }
}