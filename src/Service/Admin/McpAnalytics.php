<?php

declare(strict_types=1);

namespace App\Service\Admin;

use Psr\Log\LoggerInterface;

class McpAnalytics
{
    public const OPERATIONS = [
        'search_articles', 'get_article', 'list_latest', 'list_by_author',
        'list_by_topic', 'list_topics', 'search_books', 'get_book', 'search_book_sections',
    ];

    private const PREFIX = 'mcp_analytics:v1:';
    private const RECORD = <<<'LUA'
        redis.call('HINCRBY', KEYS[1], ARGV[1] .. ':requests', 1)
        redis.call('HINCRBY', KEYS[1], ARGV[1] .. ':errors', ARGV[2])
        redis.call('HINCRBY', KEYS[1], ARGV[1] .. ':duration_us', ARGV[3])
        redis.call('EXPIRE', KEYS[1], 691200)
        return 1
        LUA;

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(string $operation, int $status, int $durationUs): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unknown MCP analytics operation.');
        }

        try {
            $date = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($this->redis->eval(self::RECORD, [
                self::PREFIX . $date->format('Y-m-d'), $operation,
                $status >= 400 ? 1 : 0, max(0, $durationUs),
            ], 1) !== 1) {
                throw new \RuntimeException('MCP analytics write failed.');
            }
        } catch (\RedisException | \RuntimeException $e) {
            $this->logger->error('Failed to record MCP analytics', ['exception' => $e]);
        }
    }

    /**
     * @return array{error: true}|array{
     *     requests: int, errors: int, average_ms: ?float,
     *     operations: list<array{operation: string, requests: int, errors: int, average_ms: float}>
     * }
     */
    public function snapshot(): array
    {
        try {
            $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
            /** @var array<string, array{requests: int, errors: int, duration_us: int}> $totals */
            $totals = array_fill_keys(self::OPERATIONS, ['requests' => 0, 'errors' => 0, 'duration_us' => 0]);
            for ($day = 0; $day < 7; ++$day) {
                $data = $this->redis->hGetAll(self::PREFIX . $today->modify("-$day days")->format('Y-m-d'));
                if ($data === false) {
                    throw new \RuntimeException('MCP analytics read failed.');
                }
                foreach (self::OPERATIONS as $operation) {
                    foreach (['requests', 'errors', 'duration_us'] as $field) {
                        $totals[$operation][$field] += (int) ($data[$operation . ':' . $field] ?? 0);
                    }
                }
            }

            $requests = array_sum(array_column($totals, 'requests'));
            $duration = array_sum(array_column($totals, 'duration_us'));
            $operations = [];
            foreach ($totals as $operation => $metrics) {
                if ($metrics['requests'] > 0) {
                    $operations[] = [
                        'operation' => $operation,
                        'requests' => $metrics['requests'],
                        'errors' => $metrics['errors'],
                        'average_ms' => $metrics['duration_us'] / $metrics['requests'] / 1000.0,
                    ];
                }
            }
            usort($operations, static fn (array $a, array $b): int => $b['requests'] <=> $a['requests']);

            return [
                'requests' => $requests,
                'errors' => array_sum(array_column($totals, 'errors')),
                'average_ms' => $requests > 0 ? $duration / $requests / 1000.0 : null,
                'operations' => $operations,
            ];
        } catch (\RedisException | \RuntimeException $e) {
            $this->logger->error('Failed to read MCP analytics', ['exception' => $e]);

            return ['error' => true];
        }
    }
}
