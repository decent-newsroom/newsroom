<?php

declare(strict_types=1);

namespace AppTests\Service;

use App\Repository\VisitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminVisitDetailSampleTest extends KernelTestCase
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
                session_id VARCHAR(255),
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

    public function testEachDetailMetricUsesTrackedRecentVisits(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (route, visited_at, session_id, is_bot) VALUES
                ('/topics', CURRENT_DATE, 'one', FALSE),
                ('/search', CURRENT_DATE, 'one', FALSE),
                ('/search', CURRENT_DATE, 'two', FALSE),
                ('/api/search', CURRENT_DATE, 'three', FALSE),
                ('/topics', CURRENT_DATE, 'four', TRUE),
                ('/topics', CURRENT_DATE - INTERVAL '31 days', 'five', FALSE)
            SQL);

        /** @var VisitRepository $repository */
        $repository = self::getContainer()->get(VisitRepository::class);
        $since = new \DateTimeImmutable('-30 days');

        self::assertSame(3, $repository->getAdminDetailSampleMetric('visits', $since));
        self::assertSame(2, $repository->getAdminDetailSampleMetric('visitors', $since));
        self::assertSame(1.5, $repository->getAdminDetailSampleMetric('average', $since));
        self::assertSame(50.0, $repository->getAdminDetailSampleMetric('bounce', $since));

        $sessions = $repository->getRecentSessionsFromSample(new \DateTimeImmutable('-7 days'));
        self::assertCount(1, $sessions);
        self::assertSame('one', $sessions[0]['sessionId']);
        self::assertSame(2, (int) $sessions[0]['visitCount']);
    }
}
