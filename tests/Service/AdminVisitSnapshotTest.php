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
                       WHEN i <= 70000 THEN '/top-a'
                       WHEN i <= 85000 THEN '/top-b'
                       WHEN i <= 92000 THEN '/top-c'
                       WHEN i <= 97000 THEN '/top-d'
                       WHEN i <= 99990 THEN '/top-e'
                       ELSE '/minor'
                   END,
                   CASE
                       WHEN i <= 70000 THEN 'duplicate-session'
                       WHEN i <= 85000 THEN 'b-' || i
                       WHEN i <= 92000 THEN 'c-' || i
                       WHEN i <= 97000 THEN 'd-' || i
                       WHEN i <= 99990 THEN 'e-' || i
                       ELSE NULL
                   END,
                   CASE WHEN i BETWEEN 99986 AND 99990 THEN 'https://source.example/' || i ELSE NULL END,
                   FALSE
            FROM generate_series(1, 99995) AS series(i)
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

        self::assertSame(99995, $snapshot['visits']);
        self::assertSame(29991, $snapshot['unique_sessions']);
        self::assertSame(5, $snapshot['referred_visits']);
        self::assertSame(99999, $snapshot['sampled_records']);
        self::assertSame(100000, $snapshot['sample_limit']);
        self::assertSame(24, $snapshot['window_hours']);
        self::assertTrue($snapshot['capped']);
        self::assertSame([
            ['route' => '/top-a', 'count' => 70000],
            ['route' => '/top-b', 'count' => 15000],
            ['route' => '/top-c', 'count' => 7000],
            ['route' => '/top-d', 'count' => 5000],
            ['route' => '/top-e', 'count' => 2990],
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