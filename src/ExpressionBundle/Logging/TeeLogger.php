<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Tee PSR-3 logger: forwards every record to each wrapped logger.
 * Used to stream expression evaluation logs to both the app logger
 * (stderr / monolog) and a {@see MercureProgressLogger} simultaneously.
 */
final class TeeLogger extends AbstractLogger
{
    /** @var LoggerInterface[] */
    private array $loggers;

    public function __construct(LoggerInterface ...$loggers)
    {
        $this->loggers = $loggers;
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        foreach ($this->loggers as $logger) {
            try {
                $logger->log($level, $message, $context);
            } catch (\Throwable) {
                // A single broken sink must never prevent the others from recording.
            }
        }
    }
}
