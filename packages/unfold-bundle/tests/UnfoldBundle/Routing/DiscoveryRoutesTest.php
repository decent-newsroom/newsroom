<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Routing;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DiscoveryRoutesTest extends TestCase
{
    public function testDiscoveryRoutesPrecedeTheSiteCatchAll(): void
    {
        $routes = Yaml::parseFile(
            dirname(__DIR__, 3) . '/Resources/config/routes.yaml',
        );
        $routeNames = array_keys($routes);
        $catchAllPosition = array_search('unfold_site', $routeNames, true);

        self::assertIsInt($catchAllPosition);

        foreach (['unfold_rss', 'unfold_feed', 'unfold_category_rss', 'unfold_sitemap', 'unfold_robots'] as $routeName) {
            $routePosition = array_search($routeName, $routeNames, true);

            self::assertIsInt($routePosition);
            self::assertLessThan($catchAllPosition, $routePosition, $routeName . ' must precede unfold_site');
        }
    }

    public function testDiscoveryRoutePathsRemainCanonical(): void
    {
        $routes = Yaml::parseFile(
            dirname(__DIR__, 3) . '/Resources/config/routes.yaml',
        );

        self::assertSame('/rss.xml', $routes['unfold_rss']['path']);
        self::assertSame('/feed.xml', $routes['unfold_feed']['path']);
        self::assertSame('/{category}/rss.xml', $routes['unfold_category_rss']['path']);
        self::assertSame('/sitemap.xml', $routes['unfold_sitemap']['path']);
        self::assertSame('/robots.txt', $routes['unfold_robots']['path']);
    }
}
