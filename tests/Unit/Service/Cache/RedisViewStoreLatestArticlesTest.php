<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Cache;

use App\ReadModel\RedisView\RedisViewFactory;
use App\Service\Cache\RedisViewStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RedisViewStoreLatestArticlesTest extends TestCase
{
    public function testLegacyLatestArticlesKeyCannotSupplyCachedRecentFeed(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('get')
            ->with('view:articles:latest:v2')
            ->willReturn(false);

        $store = new RedisViewStore(
            $redis,
            $this->createMock(RedisViewFactory::class),
            new NullLogger(),
        );

        self::assertNull($store->fetchLatestArticles());
    }
}
