<?php

declare(strict_types=1);

namespace AppTests\Service;

use App\Repository\VisitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuthorStatsRepositoryTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE visit (
                id BIGSERIAL PRIMARY KEY,
                route VARCHAR(255) NOT NULL,
                visited_at TIMESTAMP NOT NULL,
                session_id VARCHAR(255) DEFAULT NULL,
                is_bot BOOLEAN NOT NULL DEFAULT FALSE
            ) ON COMMIT PRESERVE ROWS
            SQL);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS pg_temp.visit');
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testSummaryPreservesCountingRulesAndDistinctVisitorsAcrossDays(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (route, visited_at, session_id, is_bot) VALUES
                ('/p/alice', '2026-09-19 12:00:00', 'returning', FALSE),
                ('/p/alice/d/post', '2026-09-25 12:00:00', 'returning', FALSE),
                ('/p/alice/d/post', '2026-09-26 10:00:00', NULL, FALSE),
                ('/p/alice/d/post/draft', '2026-09-26 11:00:00', 'draft-reader', FALSE),
                ('/p/alice/articles', '2026-09-26 11:30:00', 'bot', TRUE),
                ('/p/alice', '2026-09-19 11:59:59', 'monthly', FALSE),
                ('/p/alice', '2026-08-27 12:00:00', 'monthly', FALSE),
                ('/p/alice', '2026-08-27 11:59:59', 'outside', FALSE),
                ('/p/bob', '2026-09-26 11:00:00', 'other-author', FALSE)
            SQL);
        $repository = self::getContainer()->get(VisitRepository::class);
        $now = new \DateTimeImmutable('2026-09-26 12:00:00');
        self::assertSame([
            'visitsLast7Days' => 5,
            'uniqueVisitorsLast7Days' => 3,
            'visitsLast24Hours' => 4,
            'uniqueVisitorsLast24Hours' => 3,
            'visitBreakdownLast7Days' => ['profile' => 2, 'articles' => 2, 'total' => 4],
        ], $repository->getAuthorStatsSummary('alice', 7, $now));
        self::assertSame([
            'visitsLast30Days' => 7,
            'uniqueVisitorsLast30Days' => 4,
        ], $repository->getAuthorStatsSummary('alice', 30, $now));
    }

    public function testChartUsesWholeCalendarDaysAndFillsGaps(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (route, visited_at, session_id, is_bot) VALUES
                ('/p/alice', '2026-08-27 23:59:59', 'outside', FALSE),
                ('/p/alice', '2026-08-28 00:00:00', 'returning', FALSE),
                ('/p/alice/d/post', '2026-08-28 23:59:59', 'returning', FALSE),
                ('/p/alice/d/post/draft', '2026-09-26 09:00:00', NULL, FALSE),
                ('/p/alice', '2026-09-26 23:59:59', 'returning', TRUE),
                ('/p/alice', '2026-09-27 00:00:00', 'future', FALSE),
                ('/p/bob', '2026-09-26 12:00:00', 'other-author', FALSE)
            SQL);
        $repository = self::getContainer()->get(VisitRepository::class);
        $chart = $repository->getAuthorStatsChart('alice', 30, new \DateTimeImmutable('2026-09-26 12:00:00'));
        self::assertCount(30, $chart);
        self::assertSame(['day' => '2026-08-28', 'visits' => 2, 'uniqueVisitors' => 1], $chart[0]);
        self::assertSame(['day' => '2026-08-29', 'visits' => 0, 'uniqueVisitors' => 0], $chart[1]);
        self::assertSame(['day' => '2026-09-26', 'visits' => 2, 'uniqueVisitors' => 1], $chart[29]);
        self::assertSame(4, array_sum(array_column($chart, 'visits')));
    }

    public function testEmptyPeriodReturnsZerosAndThirtyCalendarDays(): void
    {
        $repository = self::getContainer()->get(VisitRepository::class);
        $summary = $repository->getAuthorStatsSummary('nobody', 7);
        self::assertSame(0, $summary['visitsLast7Days']);
        self::assertSame(0, $summary['uniqueVisitorsLast7Days']);
        self::assertSame(['profile' => 0, 'articles' => 0, 'total' => 0], $summary['visitBreakdownLast7Days']);
        $chart = $repository->getAuthorStatsChart('nobody');
        self::assertCount(30, $chart);
        self::assertSame(0, array_sum(array_column($chart, 'visits')));
        self::assertSame(0, array_sum(array_column($chart, 'uniqueVisitors')));
    }
}
