<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\AuthorContentCacheInvalidator;
use App\Service\Admin\PubkeyContentPurger;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Elastica\Index;
use Elastica\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PubkeyContentPurgerTest extends TestCase
{
    public function testPurgeIsAuthorWideAndSearchPrecedesTransactionalLocalDeletion(): void
    {
        $hex = str_repeat('a', 64);
        $searchDeleted = false;
        $index = $this->createMock(Index::class);
        $index->expects(self::once())->method('deleteByQuery')->with(
            self::callback(static fn ($query): bool => $query->toArray() === ['terms' => ['pubkey' => [$hex]]]),
            ['refresh' => true],
        )->willReturnCallback(static function () use (&$searchDeleted): Response {
            $searchDeleted = true;
            return new Response(['deleted' => 200]);
        });
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): array => $work($connection));
        $connection->expects(self::exactly(6))->method('executeStatement')->with(
            self::callback(static fn (string $sql): bool => str_starts_with($sql, 'DELETE FROM ')
                && str_contains($sql, 'LOWER(pubkey) IN (:pubkeys)')),
            ['pubkeys' => [$hex]], ['pubkeys' => ArrayParameterType::STRING],
        )->willReturnCallback(static function () use (&$searchDeleted): int {
            self::assertTrue($searchDeleted);
            return 1;
        });
        $cache = $this->createMock(AuthorContentCacheInvalidator::class);
        $cache->expects(self::exactly(2))->method('invalidate');

        $result = (new PubkeyContentPurger($connection, $index, true, $cache, new NullLogger()))->purge([$hex]);

        self::assertSame(200, $result['elasticsearch']);
        self::assertSame(1, $result['article']);
        self::assertSame(1, $result['current_record']);
        self::assertSame(1, $result['parsed_reference']);
    }

    /** @dataProvider searchFailureProvider */
    public function testSearchFailureRetainsLocalRowsForRetry(array $result): void
    {
        $index = $this->createMock(Index::class);
        $index->method('deleteByQuery')->willReturn(new Response($result));
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $connection->expects(self::never())->method('transactional');
        $cache = $this->createMock(AuthorContentCacheInvalidator::class);
        $cache->expects(self::never())->method('invalidate');
        $this->expectException(\RuntimeException::class);

        (new PubkeyContentPurger($connection, $index, true, $cache, new NullLogger()))->purge([str_repeat('a', 64)]);
    }

    public static function searchFailureProvider(): array
    {
        return [
            'timed out' => [['timed_out' => true]],
            'failed operation' => [['failures' => [['reason' => 'failed']]]],
            'version conflict' => [['version_conflicts' => 1]],
        ];
    }

    public function testDisabledSearchStillPurgesLocalRows(): void
    {
        $index = $this->createMock(Index::class);
        $index->expects(self::never())->method('deleteByQuery');
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $work): array => $work($connection));
        $connection->method('executeStatement')->willReturn(0);
        $cache = $this->createMock(AuthorContentCacheInvalidator::class);
        $cache->expects(self::exactly(2))->method('invalidate');

        $result = (new PubkeyContentPurger($connection, $index, false, $cache, new NullLogger()))->purge([str_repeat('a', 64)]);

        self::assertSame(0, array_sum($result));
    }
}
