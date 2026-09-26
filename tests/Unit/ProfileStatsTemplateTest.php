<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ProfileStatsTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new ChainLoader([
            new ArrayLoader([
                'layout.html.twig' => '<head>{% block stylesheets %}{% endblock %}</head>{% block body %}{% endblock %}',
            ]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $this->twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $this->twig->addFunction(new TwigFunction('path', static fn (string $route): string => '/' . $route));
    }

    public function testShellNeedsNoAnalyticsDataAndDoesNotCachePersonalStats(): void
    {
        $html = $this->twig->render('stats/index.html.twig');

        self::assertSame(3, substr_count($html, '<turbo-frame'));
        self::assertSame(2, substr_count($html, 'loading="lazy"'));
        self::assertSame(1, substr_count($html, 'loading="eager"'));
        self::assertStringContainsString('name="turbo-cache-control" content="no-cache"', $html);
        self::assertStringNotContainsString('stat-card__value', $html);
        foreach (['week', 'month', 'chart'] as $section) {
            self::assertStringContainsString('id="stats-' . $section . '" src="/profile_stats_' . $section . '"', $html);
        }
    }

    public function testSuccessfulEmptyWeekDisplaysRealZeros(): void
    {
        $html = $this->twig->render('stats/_week.html.twig', [
            'visitsLast7Days' => 0,
            'visitsLast24Hours' => 0,
            'uniqueVisitorsLast7Days' => 0,
            'uniqueVisitorsLast24Hours' => 0,
            'visitBreakdownLast7Days' => ['articles' => 0, 'profile' => 0],
            'topArticlesLast7Days' => [],
        ]);

        self::assertStringContainsString('<turbo-frame id="stats-week">', $html);
        self::assertSame(4, substr_count($html, 'stat-card__value">0'));
        self::assertStringContainsString('stats.noArticleViewsWeek', $html);
        self::assertStringNotContainsString(' src=', $html);
    }

    public function testSuccessfulEmptyMonthDisplaysRealZeros(): void
    {
        $html = $this->twig->render('stats/_month.html.twig', [
            'visitsLast30Days' => 0,
            'uniqueVisitorsLast30Days' => 0,
            'topArticlesLast30Days' => [],
        ]);

        self::assertStringContainsString('<turbo-frame id="stats-month">', $html);
        self::assertSame(2, substr_count($html, 'stat-card__value">0'));
        self::assertStringContainsString('stats.noArticleViewsMonth', $html);
        self::assertStringNotContainsString(' src=', $html);
    }

    public function testChartWithoutRowsShowsEmptyState(): void
    {
        $html = $this->twig->render('stats/_chart.html.twig', ['chartData' => []]);

        self::assertStringContainsString('stats.noTrafficYet', $html);
        self::assertStringNotContainsString('<canvas', $html);
        self::assertStringNotContainsString(' src=', $html);
    }

    public function testChartWithZeroFilledDaysStillRenders(): void
    {
        $html = $this->twig->render('stats/_chart.html.twig', [
            'chartData' => [['day' => '2026-09-26', 'visits' => 0, 'uniqueVisitors' => 0]],
        ]);

        self::assertStringContainsString('<turbo-frame id="stats-chart">', $html);
        self::assertStringContainsString('<canvas', $html);
        self::assertStringContainsString('unique-visitors-value=', $html);
        self::assertStringNotContainsString('stats.noTrafficYet', $html);
        self::assertStringNotContainsString(' src=', $html);
    }

    public function testFailedSectionShowsRetryAndNeverZeroMetrics(): void
    {
        $html = $this->twig->render('stats/_error.html.twig', ['frameId' => 'stats-month']);

        self::assertStringContainsString('<turbo-frame id="stats-month">', $html);
        self::assertStringContainsString('stats.loadError', $html);
        self::assertStringContainsString('analytics--stats-frame#retry', $html);
        self::assertStringNotContainsString('stat-card__value', $html);
        self::assertStringNotContainsString(' src=', $html);
    }
}