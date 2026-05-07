<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\RelayFilterStatsStore;
use App\Service\Nostr\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for RelayFilterStatsStore — focuses on signature derivation
 * (privacy-preserving) and aggregation logic.
 *
 * @see documentation/Nostr/relay-filter-stats.md
 */
class RelayFilterStatsStoreTest extends TestCase
{
    private function makeStore(\Redis $redis): RelayFilterStatsStore
    {
        $registry = $this->createMock(RelayRegistry::class);
        $registry->method('isConfiguredRelay')->willReturn(false);
        return new RelayFilterStatsStore($redis, new NullLogger(), $registry);
    }

    // --- signature ---

    public function testSignatureContainsKindsButNeverAuthorPubkeys(): void
    {
        $store = $this->makeStore($this->createMock(\Redis::class));

        $sig = $store->signature([
            'kinds' => [30023, 1],
            'authors' => [
                '929dd94e6cc8a6665665a1e1fc043952c014c16c1735578e3436cd4510b1e829',
                '0c5f4fed28326d08b7be6c920070a5c12aa862ea3a75a39502e249916362ad11',
            ],
            'limit' => 20,
            'since' => 1700000000,
        ]);

        // Kinds are sorted asc and rendered explicitly
        $this->assertStringContainsString('kinds=[1,30023]', $sig);
        // Authors are reduced to a count
        $this->assertStringContainsString('authors=N2', $sig);
        // No hex pubkey fragments may leak through
        $this->assertStringNotContainsString('929dd94e', $sig);
        $this->assertStringNotContainsString('0c5f4fed', $sig);
        // Since is a presence flag — never the timestamp
        $this->assertStringContainsString('since', $sig);
        $this->assertStringNotContainsString('1700000000', $sig);
        // Limit is preserved exactly
        $this->assertStringContainsString('limit=20', $sig);
    }

    public function testSignatureCollapsesSameShape(): void
    {
        $store = $this->makeStore($this->createMock(\Redis::class));

        $a = $store->signature(['kinds' => [30023], 'authors' => ['a', 'b'], 'limit' => 50]);
        $b = $store->signature(['kinds' => [30023], 'authors' => ['x', 'y'], 'limit' => 50]);

        $this->assertSame($a, $b, 'Different author lists of same length should produce identical signatures');
    }

    public function testSignatureRecognizesTagFiltersByKeyOnly(): void
    {
        $store = $this->makeStore($this->createMock(\Redis::class));

        $sig = $store->signature([
            'kinds' => [1111],
            '#e' => ['abc1', 'abc2', 'abc3'],
            '#p' => ['def1'],
        ]);

        $this->assertStringContainsString('#e=N3', $sig);
        $this->assertStringContainsString('#p=N1', $sig);
        $this->assertStringNotContainsString('abc1', $sig);
        $this->assertStringNotContainsString('def1', $sig);
    }

    public function testSignatureNormalizesUppercaseTagKeys(): void
    {
        $store = $this->makeStore($this->createMock(\Redis::class));

        $upper = $store->signature([
            'kinds' => [1111, 9735],
            '#A' => ['30023:pubkey:slug'],
        ]);

        $lower = $store->signature([
            'kinds' => [1111, 9735],
            '#a' => ['30023:pubkey:slug'],
        ]);

        $this->assertSame($upper, $lower);
        $this->assertStringContainsString('#a=N1', $upper);
        $this->assertStringNotContainsString('#A=', $upper);
    }

    public function testSignatureForEmptyFilterIsEmpty(): void
    {
        $store = $this->makeStore($this->createMock(\Redis::class));
        $this->assertSame('empty', $store->signature([]));
    }

    public function testSignatureIsDeterministic(): void
    {
        $store = $this->makeStore($this->createMock(\Redis::class));

        $a = $store->signature(['#p' => ['x'], 'kinds' => [1, 30023], '#e' => ['y']]);
        $b = $store->signature(['kinds' => [30023, 1], '#e' => ['y'], '#p' => ['x']]);

        $this->assertSame($a, $b, 'Field order and kind order must not affect signature');
    }

    // --- recording ---

    public function testRecordRequestIncrementsCount(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects($this->once())
            ->method('hIncrBy')
            ->with(
                $this->stringContains('relay_filter_stats:'),
                $this->stringContains('|count'),
                1,
            )
            ->willReturn(1);
        $redis->method('expire')->willReturn(true);
        $redis->method('sAdd')->willReturn(1);

        $store = $this->makeStore($redis);
        $store->recordRequest('wss://relay.example.com', 'kinds=[30023];limit=20');
    }

    public function testRecordEoseUpdatesAggregates(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('hIncrBy')->willReturn(1);
        $redis->method('hIncrByFloat')->willReturn(450.0);
        // First call: no current max → returns false
        $redis->method('hGet')->willReturn(false);
        $redis->method('hSet')->willReturn(1);
        $redis->method('expire')->willReturn(true);

        $store = $this->makeStore($redis);
        $store->recordEose('wss://relay.example.com', 'kinds=[1]', 450, 12);
    }
}

