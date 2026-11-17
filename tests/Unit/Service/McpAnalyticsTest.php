<?php

declare(strict_types=1);

namespace AppTests\Unit\Service;

use App\Service\Admin\McpAnalytics;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class McpAnalyticsTest extends TestCase
{
    public function testSnapshotSumsSevenUtcDaysAndWeightsLatencyByRequests(): void
    {
        $redis = $this->createMock(\Redis::class);
        $days = [];
        $redis->expects(self::exactly(7))->method('hGetAll')
            ->willReturnCallback(static function (string $key) use (&$days): array {
                $days[] = $key;

                return match (count($days)) {
                    1 => ['search_articles:requests' => '3', 'search_articles:duration_us' => '60000'],
                    2 => [
                        'search_articles:requests' => '1', 'search_articles:errors' => '1',
                        'search_articles:duration_us' => '20000',
                        'get_book:requests' => '1', 'get_book:duration_us' => '120000',
                    ],
                    default => [],
                };
            });
        $result = (new McpAnalytics($redis, $this->createMock(LoggerInterface::class)))->snapshot();

        self::assertSame(5, $result['requests']);
        self::assertSame(1, $result['errors']);
        self::assertSame(40.0, $result['average_ms']);
        self::assertSame([
            ['operation' => 'search_articles', 'requests' => 4, 'errors' => 1, 'average_ms' => 20.0],
            ['operation' => 'get_book', 'requests' => 1, 'errors' => 0, 'average_ms' => 120.0],
        ], $result['operations']);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        self::assertSame('mcp_analytics:v1:' . $today->format('Y-m-d'), $days[0]);
        self::assertSame('mcp_analytics:v1:' . $today->modify('-6 days')->format('Y-m-d'), $days[6]);
    }

    public function testEmptySnapshotHasNoInventedLatency(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('hGetAll')->willReturn([]);
        self::assertSame([
            'requests' => 0, 'errors' => 0, 'average_ms' => null, 'operations' => [],
        ], (new McpAnalytics($redis, $this->createMock(LoggerInterface::class)))->snapshot());
    }

    /** @dataProvider statusProvider */
    public function testRecordingIsAtomicAndBounded(int $status, int $error): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('eval')
            ->with(
                self::callback(static fn (string $lua): bool => str_contains($lua, '691200')
                    && substr_count($lua, 'HINCRBY') === 3),
                self::callback(static fn (array $args): bool => preg_match('/^mcp_analytics:v1:\d{4}-\d{2}-\d{2}$/D', $args[0]) === 1
                    && array_slice($args, 1) === ['get_book', $error, 12000]),
                1,
            )->willReturn(1);
        (new McpAnalytics($redis, $this->createMock(LoggerInterface::class)))->record('get_book', $status, 12000);
    }

    public static function statusProvider(): array
    {
        return [[200, 0], [399, 0], [400, 1], [404, 1], [500, 1]];
    }

    public function testReadFailureIsNotReportedAsZeroTraffic(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('hGetAll')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        self::assertSame(['error' => true], (new McpAnalytics($redis, $logger))->snapshot());
    }

    public function testWriteFailureIsLoggedWithoutBreakingApiResponse(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willThrowException(new \RedisException('unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        (new McpAnalytics($redis, $logger))->record('get_book', 500, 1);
    }

    public function testUnknownOperationCannotCreateUnboundedFields(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('eval');
        $this->expectException(\InvalidArgumentException::class);
        (new McpAnalytics($redis, $this->createMock(LoggerInterface::class)))->record('private-query', 200, 1);
    }
}
