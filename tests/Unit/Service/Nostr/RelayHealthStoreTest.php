<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\RelayHealthStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for RelayHealthStore — health score algorithm, muting,
 * and URL normalization consistency.
 *
 * Uses a mock Redis to avoid a live connection requirement.
 *
 * @see documentation/Nostr/relay-management-review.md §9
 */
class RelayHealthStoreTest extends TestCase
{
    private \Redis $redis;
    private RelayHealthStore $store;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->store = new RelayHealthStore($this->redis, new NullLogger());
    }

    // --- Health defaults ---

    public function testGetHealthReturnsDefaultsWhenNoData(): void
    {
        $this->redis->method('hGetAll')->willReturn([]);

        $health = $this->store->getHealth('wss://example.com');

        $this->assertNull($health['last_success']);
        $this->assertNull($health['last_failure']);
        $this->assertSame(0, $health['consecutive_failures']);
        $this->assertNull($health['avg_latency_ms']);
        $this->assertFalse($health['auth_required']);
        $this->assertSame('none', $health['auth_status']);
        $this->assertNull($health['last_event_received']);
        $this->assertSame([], $health['heartbeats']);
    }

    public function testGetHealthParsesRawData(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'last_success' => '1700000000',
            'last_failure' => '1699999000',
            'consecutive_failures' => '2',
            'avg_latency_ms' => '150.5',
            'auth_required' => '1',
            'auth_status' => 'ephemeral',
            'last_event_received' => '1700000100',
            'heartbeat_articles' => '1700000200',
            'heartbeat_media' => '1700000100',
        ]);

        $health = $this->store->getHealth('wss://example.com');

        $this->assertSame(1700000000, $health['last_success']);
        $this->assertSame(1699999000, $health['last_failure']);
        $this->assertSame(2, $health['consecutive_failures']);
        $this->assertSame(150.5, $health['avg_latency_ms']);
        $this->assertTrue($health['auth_required']);
        $this->assertSame('ephemeral', $health['auth_status']);
        $this->assertSame(1700000100, $health['last_event_received']);
        $this->assertSame(['articles' => 1700000200, 'media' => 1700000100], $health['heartbeats']);
    }

    // --- Health score ---

    public function testHealthScoreIsPerfectWithNoFailuresNoLatency(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '0',
            'last_success' => (string) time(), // just now
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $score = $this->store->getHealthScore('wss://example.com');

        // With 0 failures + recent success → close to 1.0
        $this->assertGreaterThanOrEqual(0.95, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function testHealthScoreDegradesWithFailures(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '5',
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $score = $this->store->getHealthScore('wss://example.com');

        // 5 consecutive failures → penalty of 0.5 → base score 0.5
        $this->assertLessThan(0.6, $score);
        $this->assertGreaterThan(0.0, $score);
    }

    public function testHealthScoreIsZeroWhenMuted(): void
    {
        $this->redis->method('sIsMember')->willReturn(true);

        $score = $this->store->getHealthScore('wss://example.com');

        $this->assertSame(0.0, $score);
    }

    public function testHealthScoreIncludesRecencyBonus(): void
    {
        // Recent success (within last hour) should boost score
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '3',
            'last_success' => (string) (time() - 1800), // 30 min ago
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $scoreWithRecent = $this->store->getHealthScore('wss://example.com');

        // Without recency (same failures but no last_success)
        $storeNoRecency = new RelayHealthStore(
            $this->createRedisWithData(['consecutive_failures' => '3']),
            new NullLogger(),
        );
        $scoreWithoutRecent = $storeNoRecency->getHealthScore('wss://example.com');

        // Score WITH a recent success should be higher than WITHOUT
        $this->assertGreaterThan($scoreWithoutRecent, $scoreWithRecent);
    }

    public function testHealthScorePenalizesHighLatency(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '0',
            'avg_latency_ms' => '1500', // 1.5 seconds — high
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $score = $this->store->getHealthScore('wss://example.com');

        // High latency should drag the score below perfect
        $this->assertLessThan(0.9, $score);
    }

    public function testHealthScoreMaxFailuresClampToZeroBase(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '15',
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $score = $this->store->getHealthScore('wss://example.com');

        // 15 failures → penalty capped at 1.0 → base score 0.0
        $this->assertSame(0.0, $score);
    }

    // --- isHealthy ---

    public function testIsHealthyWithNoFailures(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '0',
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $this->assertTrue($this->store->isHealthy('wss://example.com'));
    }

    public function testIsUnhealthyWhenMuted(): void
    {
        $this->redis->method('sIsMember')->willReturn(true);

        $this->assertFalse($this->store->isHealthy('wss://example.com'));
    }

    public function testIsUnhealthyAfterThreeConsecutiveFailures(): void
    {
        $this->redis->method('hGetAll')->willReturn([
            'consecutive_failures' => '3',
        ]);
        $this->redis->method('sIsMember')->willReturn(false);

        $this->assertFalse($this->store->isHealthy('wss://example.com'));
    }

    // --- URL normalization consistency ---

    public function testKeyNormalizationIsCaseInsensitive(): void
    {
        // Both calls should write to the same Redis key (verified by capture)
        $keysWritten = [];
        $this->redis->method('hSet')->willReturnCallback(
            function (string $key, string $field, string $value) use (&$keysWritten) {
                $keysWritten[] = $key;
                return 1;
            }
        );
        $this->redis->method('expire')->willReturn(true);

        $this->store->recordSuccess('wss://Relay.Damus.IO/');
        $this->store->recordSuccess('wss://relay.damus.io');

        // Both should produce the same key
        $uniqueKeys = array_values(array_unique($keysWritten));
        $this->assertCount(1, $uniqueKeys);
    }

    // --- Helper ---

    private function createRedisWithData(array $data): \Redis
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('hGetAll')->willReturn($data);
        $redis->method('sIsMember')->willReturn(false);
        return $redis;
    }
}

