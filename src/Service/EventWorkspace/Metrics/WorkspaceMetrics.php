<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace\Metrics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/** Request-local counters; never accepts identities or arbitrary labels. */
final class WorkspaceMetrics implements ResetInterface
{
    public const FIELDS = [
        'requests', 'queries', 'queued_fetches', 'exceptions',
        'http_2xx', 'http_3xx', 'http_4xx', 'http_5xx', 'http_other', 'no_response',
        'duration_le_5_ms', 'duration_le_10_ms', 'duration_le_25_ms', 'duration_le_50_ms',
        'duration_le_100_ms', 'duration_le_250_ms', 'duration_le_500_ms',
        'duration_le_1000_ms', 'duration_le_2500_ms', 'duration_le_5000_ms', 'duration_over_5000_ms',
        'queries_0', 'queries_1_5', 'queries_6_15', 'queries_16_30', 'queries_31_100', 'queries_over_100',
        'fetches_0', 'fetches_1', 'fetches_2_4', 'fetches_over_4',
        'resolution_available', 'resolution_missing', 'resolution_unavailable', 'resolution_restricted',
        'resolution_invalid', 'resolution_loading', 'resolution_failed', 'resolution_limited',
        'resolution_unresolved', 'resolution_other',
    ];

    private bool $active = false;
    private int $started = 0;
    private int $queries = 0;
    private int $fetches = 0;
    private bool $exception = false;
    /** @var array<string, int> */
    private array $resolutions = [];

    public function __construct(
        #[Autowire('%event_workspace_metrics_enabled%')]
        private readonly bool $enabled,
    ) {}

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function begin(): void
    {
        $this->reset();
        if ($this->enabled) {
            $this->active = true;
            $this->started = hrtime(true);
        }
    }

    public function query(): void
    {
        if ($this->active) {
            $this->queries = min(100000, $this->queries + 1);
        }
    }

    public function queuedFetch(): void
    {
        if ($this->active) {
            $this->fetches = min(10000, $this->fetches + 1);
        }
    }

    public function resolution(string $status): void
    {
        if (!$this->active) {
            return;
        }
        $key = 'resolution_' . $status;
        if (!in_array($key, self::FIELDS, true) || !str_starts_with($key, 'resolution_')) {
            $key = 'resolution_other';
        }
        $this->resolutions[$key] = min(10000, ($this->resolutions[$key] ?? 0) + 1);
    }

    public function exception(): void
    {
        if ($this->active) {
            $this->exception = true;
        }
    }

    /** @return array<string, int>|null */
    public function finish(?int $status): ?array
    {
        if (!$this->active) {
            return null;
        }
        $duration = max(0, (hrtime(true) - $this->started) / 1_000_000);
        $metrics = array_fill_keys(self::FIELDS, 0);
        $metrics['requests'] = 1;
        $metrics['queries'] = $this->queries;
        $metrics['queued_fetches'] = $this->fetches;
        $metrics['exceptions'] = (int) $this->exception;
        $http = $status === null ? 'no_response' : match (intdiv($status, 100)) {
            2 => 'http_2xx', 3 => 'http_3xx', 4 => 'http_4xx', 5 => 'http_5xx', default => 'http_other',
        };
        $metrics[$http] = 1;
        $durationBucket = 'duration_over_5000_ms';
        foreach ([5, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000] as $limit) {
            if ($duration <= $limit) {
                $durationBucket = 'duration_le_' . $limit . '_ms';
                break;
            }
        }
        $metrics[$durationBucket] = 1;
        $metrics[match (true) {
            $this->queries === 0 => 'queries_0', $this->queries <= 5 => 'queries_1_5',
            $this->queries <= 15 => 'queries_6_15', $this->queries <= 30 => 'queries_16_30',
            $this->queries <= 100 => 'queries_31_100', default => 'queries_over_100',
        }] = 1;
        $metrics[match (true) {
            $this->fetches === 0 => 'fetches_0', $this->fetches === 1 => 'fetches_1',
            $this->fetches <= 4 => 'fetches_2_4', default => 'fetches_over_4',
        }] = 1;
        foreach ($this->resolutions as $key => $count) {
            $metrics[$key] = $count;
        }
        $this->reset();

        return $metrics;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->started = $this->queries = $this->fetches = 0;
        $this->exception = false;
        $this->resolutions = [];
    }
}
