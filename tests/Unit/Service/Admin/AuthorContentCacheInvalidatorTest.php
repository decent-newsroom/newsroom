<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\AuthorContentCacheInvalidator;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class AuthorContentCacheInvalidatorTest extends TestCase
{
    public function testClearsOnlyContentPoolsAndViews(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::exactly(3))->method('clear')->willReturn(true);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('delete')->with('media_discovery_events_all_test')->willReturn(true);
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('scan')->willReturnCallback(
            static function (&$cursor, string $pattern, int $count): array {
                self::assertSame('view:*', $pattern);
                self::assertSame(500, $count);
                $cursor = 0;
                return ['view:articles:latest:v2', 'view:profile:tab:author:articles'];
            },
        );
        $redis->expects(self::once())->method('del')
            ->with(['view:articles:latest:v2', 'view:profile:tab:author:articles'])->willReturn(2);

        (new AuthorContentCacheInvalidator($redis, $pool, $pool, $pool, $cache, 'test'))->invalidate();
    }

    public function testCacheFailuresAreNotSwallowed(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('clear')->willReturn(false);
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('scan');
        $this->expectException(\RuntimeException::class);

        (new AuthorContentCacheInvalidator(
            $redis, $pool, $pool, $pool, $this->createMock(CacheInterface::class), 'test',
        ))->invalidate();
    }
}
