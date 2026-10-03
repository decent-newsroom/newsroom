<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Contract\PublicationRefreshInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CategoryMutationCacheWarmerTest extends TestCase
{
    public function testSharedCategoryInvalidationRunsBeforeRootRefreshFailure(): void
    {
        $order = [];
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::once())->method('invalidateCategoryCache')->with('30040:author:child')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'invalidate';
            });
        $refresh = $this->createMock(PublicationRefreshInterface::class);
        $refresh->expects(self::once())->method('refresh')->with('30040:author:root')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'refresh';
                throw new \RuntimeException('Refresh unavailable');
            });
        $warmer = new SiteConfigCacheWarmer($this->createMock(SiteConfigLoader::class), $provider, new NullLogger(), $refresh);

        self::assertFalse($warmer->warmCategoryMutation('30040:author:root', '30040:author:child'));
        self::assertSame(['invalidate', 'refresh'], $order);
    }

    public function testInvalidationFailureDoesNotEscapePostCommitCacheBoundary(): void
    {
        $provider = $this->createMock(ContentProvider::class);
        $provider->expects(self::once())->method('invalidateCategoryCache')->willThrowException(new \RuntimeException('Cache unavailable'));
        $refresh = $this->createMock(PublicationRefreshInterface::class);
        $refresh->expects(self::once())->method('refresh')->willThrowException(new \RuntimeException('Refresh unavailable'));
        $warmer = new SiteConfigCacheWarmer($this->createMock(SiteConfigLoader::class), $provider, new NullLogger(), $refresh);

        self::assertFalse($warmer->warmCategoryMutation('30040:author:root', '30040:author:child'));
    }
}
