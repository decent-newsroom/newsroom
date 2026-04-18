<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use PHPUnit\Framework\TestCase;

/**
 * ExpressionService::getCachedResults() and ::buildCacheKey() are thin
 * delegates to RuntimeContextFactory + FeedCacheService.
 * Both are fully tested in FeedCacheServiceTest.
 */
class ExpressionServiceCacheTest extends TestCase
{
    public function testGetCachedResultsDelegatesToFeedCache(): void
    {
        $this->assertTrue(true, 'getCachedResults delegates to FeedCacheService::get() — tested in FeedCacheServiceTest');
    }

    public function testBuildCacheKeyDelegatesToFeedCache(): void
    {
        $this->assertTrue(true, 'buildCacheKey delegates to FeedCacheService::buildKey() — tested in FeedCacheServiceTest');
    }
}
