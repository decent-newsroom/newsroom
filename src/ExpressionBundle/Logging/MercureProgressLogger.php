<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * PSR-3 logger that republishes each record as a Mercure update on a per-job
 * topic, so a waiting browser can see expression/spell evaluation progress
 * in real time while the async worker is running.
 *
 * Payload shape: {status:'log', level, message, context, ts}
 * (shares the same topic as the terminal {status:'ready'|'error'} publish).
 *
 * Hub exceptions are swallowed and surfaced to the fallback logger — a
 * broken Mercure hub must never fail an evaluation.
 */
final class MercureProgressLogger extends AbstractLogger
{
    /** PSR-3 levels ordered from least to most severe. */
    private const LEVELS = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public function __construct(
        private readonly HubInterface $hub,
        private readonly string $topic,
        private readonly LoggerInterface $fallback,
        private readonly string $minLevel = LogLevel::INFO,
    ) {}

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $levelKey = is_string($level) ? strtolower($level) : LogLevel::INFO;
        if ((self::LEVELS[$levelKey] ?? 1) < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }

        try {
            $payload = [
                'status'  => 'log',
                'level'   => $levelKey,
                'message' => (string) $message,
                'context' => $this->scrubContext($context),
                'ts'      => (int) (microtime(true) * 1000),
            ];
            $this->hub->publish(new Update($this->topic, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), false));
        } catch (\Throwable $e) {
            $this->fallback->warning('Mercure progress publish failed', [
                'topic' => $this->topic,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Keep the context JSON-safe and trim obviously noisy keys.
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function scrubContext(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            if ($v instanceof \Throwable) {
                $out[$k] = $v->getMessage();
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$k] = $v;
                continue;
            }
            if (is_array($v)) {
                // shallow-copy, stringify anything non-scalar so JSON never fails
                $sub = [];
                foreach ($v as $kk => $vv) {
                    $sub[$kk] = (is_scalar($vv) || $vv === null) ? $vv : (is_array($vv) ? '[array]' : get_debug_type($vv));
                }
                $out[$k] = $sub;
                continue;
            }
            $out[$k] = get_debug_type($v);
        }
        return $out;
    }
}
