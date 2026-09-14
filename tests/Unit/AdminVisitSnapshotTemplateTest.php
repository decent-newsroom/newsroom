<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

class AdminVisitSnapshotTemplateTest extends TestCase
{
    public function testSnapshotRendersNonzeroMetricsFromTheSharedPartial(): void
    {
        $html = $this->renderSnapshot([
            'visits' => 123,
            'unique_sessions' => 45,
            'referred_visits' => 6,
            'sample_limit' => 10000,
            'sampled_records' => 123,
        ]);

        self::assertStringContainsString('class="metric-value">123</div>', $html);
        self::assertStringContainsString('class="metric-value">45</div>', $html);
        self::assertStringContainsString('class="metric-value">6</div>', $html);
        self::assertStringNotContainsString('admin_snapshot.unavailable', $html);
    }

    public function testUnavailableSnapshotRendersNoticeWithoutFakeZeroMetrics(): void
    {
        $html = $this->renderSnapshot(['error' => true]);

        self::assertStringContainsString('admin_snapshot.unavailable', $html);
        self::assertStringNotContainsString('metric-grid', $html);
        self::assertStringNotContainsString('class="metric-value">0</div>', $html);
    }

    private function renderSnapshot(array $snapshot): string
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'));
        $twig->addFilter(new TwigFilter('trans', static function (string $key): string {
            return $key;
        }));

        return $twig->render('admin/_visit_snapshot.html.twig', ['snapshot' => $snapshot]);
    }
}
