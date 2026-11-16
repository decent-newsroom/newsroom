<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace\Metrics;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** A bounded local aggregate, with no request-level records or network sink. */
final readonly class WorkspaceMetricsStore
{
    private const MAX_BYTES = 8192;
    private const MAX_COUNT = 9007199254740991;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/event-workspace-metrics.json')]
        private string $path,
    ) {}

    /** @param array<string, int> $sample */
    public function add(array $sample): bool
    {
        $file = @fopen($this->path, 'c+');
        if ($file === false) {
            return false;
        }
        $success = false;
        try {
            $success = $this->update($file, $sample);
        } finally {
            if (!@fclose($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /** @param resource $file @param array<string, int> $sample */
    private function update($file, array $sample): bool
    {
        if (!@flock($file, LOCK_EX | LOCK_NB)) {
            return false;
        }
        $raw = @stream_get_contents($file, self::MAX_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_BYTES) {
            return false;
        }
        $total = $raw === '' ? array_fill_keys(WorkspaceMetrics::FIELDS, 0) : json_decode($raw, true);
        if (!is_array($total) || count($total) !== count(WorkspaceMetrics::FIELDS)) {
            return false;
        }
        foreach (WorkspaceMetrics::FIELDS as $field) {
            if (!isset($total[$field], $sample[$field])
                || !is_int($total[$field]) || $total[$field] < 0 || $total[$field] > self::MAX_COUNT
                || !is_int($sample[$field]) || $sample[$field] < 0 || $sample[$field] > 100000) {
                return false;
            }
            $total[$field] = min(self::MAX_COUNT, $total[$field] + $sample[$field]);
        }
        $json = json_encode($total, JSON_THROW_ON_ERROR) . "\n";
        if (!@rewind($file) || !@ftruncate($file, 0)) {
            return false;
        }
        $written = @fwrite($file, $json);
        $flushed = @fflush($file);

        return $written === strlen($json) && $flushed;
    }
}
