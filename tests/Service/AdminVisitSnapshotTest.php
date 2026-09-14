<?php

declare(strict_types=1);

namespace AppTests\Service;

use App\Repository\VisitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminVisitSnapshotTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->executeStatement(<<<'SQL'
            CREATE TEMPORARY TABLE visit (
                id BIGSERIAL PRIMARY KEY,
                visited_at TIMESTAMP NOT NULL,
                route VARCHAR(255) NOT NULL,
                session_id VARCHAR(255) DEFAULT NULL,
                referer VARCHAR(2048) DEFAULT NULL,
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

    public function testSnapshotSamplesBeforeFiltersAndAggregatesOnlyTheBoundedRows(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (visited_at, route, session_id, referer, is_bot)
            VALUES (CURRENT_TIMESTAMP, '/excluded-oldest', 'excluded-session', NULL, FALSE)
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (visited_at, route, session_id, referer, is_bot)
            SELECT CURRENT_TIMESTAMP,
                   CASE
                       WHEN i <= 7000 THEN '/top-a'
                       WHEN i <= 8500 THEN '/top-b'
                       WHEN i <= 9200 THEN '/top-c'
                       WHEN i <= 9700 THEN '/top-d'
                       WHEN i <= 9990 THEN '/top-e'
                       ELSE '/minor'
                   END,
                   CASE
                       WHEN i <= 7000 THEN 'duplicate-session'
                       WHEN i <= 8500 THEN 'b-' || i
                       WHEN i <= 9200 THEN 'c-' || i
                       WHEN i <= 9700 THEN 'd-' || i
                       WHEN i <= 9990 THEN 'e-' || i
                       ELSE NULL
                   END,
                   CASE WHEN i BETWEEN 9986 AND 9990 THEN 'https://source.example/' || i ELSE NULL END,
                   FALSE
            FROM generate_series(1, 9995) AS series(i)
            SQL);

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (visited_at, route, session_id, referer, is_bot)
            VALUES
                (CURRENT_TIMESTAMP, '/bot', 'bot-session', NULL, TRUE),
                (CURRENT_TIMESTAMP, '/api/example', 'api-session', NULL, FALSE),
                (CURRENT_TIMESTAMP, '/assets/app.js', 'asset-session', NULL, FALSE),
                (CURRENT_TIMESTAMP, '/preview/example', 'partial-session', NULL, FALSE),
                (CURRENT_TIMESTAMP, '/old-date', 'old-session', NULL, FALSE)
            SQL);
        $this->connection->executeStatement("UPDATE visit SET visited_at = CURRENT_TIMESTAMP - INTERVAL '48 hours' WHERE route = '/old-date'");

        /** @var VisitRepository $repository */
        $repository = self::getContainer()->get(VisitRepository::class);
        $snapshot = $repository->getAdminSnapshot();

        self::assertSame(9995, $snapshot['visits']);
        self::assertSame(2991, $snapshot['unique_sessions']);
        self::assertSame(5, $snapshot['referred_visits']);
        self::assertSame(9999, $snapshot['sampled_records']);
        self::assertSame(10000, $snapshot['sample_limit']);
        self::assertSame(24, $snapshot['window_hours']);
        self::assertTrue($snapshot['capped']);
        self::assertSame([
            ['route' => '/top-a', 'count' => 7000],
            ['route' => '/top-b', 'count' => 1500],
            ['route' => '/top-c', 'count' => 700],
            ['route' => '/top-d', 'count' => 500],
            ['route' => '/top-e', 'count' => 290],
        ], $snapshot['top_routes']);
    }

    public function testSnapshotReturnsEmptyMetricsWhenThereAreNoVisits(): void
    {
        /** @var VisitRepository $repository */
        $repository = self::getContainer()->get(VisitRepository::class);
        $snapshot = $repository->getAdminSnapshot();

        self::assertSame(0, $snapshot['visits']);
        self::assertSame(0, $snapshot['unique_sessions']);
        self::assertSame(0, $snapshot['referred_visits']);
        self::assertSame(0, $snapshot['sampled_records']);
        self::assertSame([], $snapshot['top_routes']);
        self::assertFalse($snapshot['capped']);
    }
}