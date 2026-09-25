<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Routing;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Http\RouteMatcher;
use PHPUnit\Framework\TestCase;

final class AboutRouteTest extends TestCase
{
    public function testAboutRouteIsReservedAheadOfCategorySlug(): void
    {
        $site = new SiteConfig('30040:owner:root', 'Magazine', '', null, [], 'owner');
        $category = new CategoryData('about', 'Category About', '30040:owner:about');
        $matcher = new RouteMatcher();

        self::assertSame(['type' => RouteMatcher::PAGE_ABOUT], $matcher->match('/about', $site, [$category]));
        self::assertSame(['type' => RouteMatcher::PAGE_ABOUT], $matcher->match('/about/', $site, [$category]));
    }
}
