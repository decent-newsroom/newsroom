<?php

declare(strict_types=1);

namespace AppTests\Service;

use App\Repository\VisitRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VisitRouteLookupTest extends KernelTestCase
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

    public function testExactPathIncludesRawBotAndApiRecordsWithinCalendarWindow(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO visit (route, visited_at, is_bot) VALUES
                ('/topics', CURRENT_DATE - INTERVAL '6 days', FALSE),
                ('/topics', CURRENT_DATE, TRUE),
                ('/topics/subpage', CURRENT_DATE, FALSE),
                ('/topics', CURRENT_DATE - INTERVAL '7 days', FALSE),
                ('/topics', CURRENT_DATE + INTERVAL '1 day', FALSE),
                ('/api/search', CURRENT_DATE, TRUE)
            SQL);

        /** @var VisitRepository $repository */
        $repository = self::getContainer()->get(VisitRepository::class);
        $since = new \DateTimeImmutable('today -6 days');
        $before = $since->modify('+7 days');

        $topics = $repository->getRawVisitCountsByExactRouteBetween('/topics', $since, $before);
        self::assertSame(2, array_sum(array_map(static fn (array $row): int => (int) $row['count'], $topics)));
        self::assertCount(2, $topics);

        $api = $repository->getRawVisitCountsByExactRouteBetween('/api/search', $since, $before);
        self::assertSame(1, (int) $api[0]['count']);
    }
}