<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Mutable PSR-3 facade that delegates to a stack of loggers.
 *
 * Used to temporarily redirect every PSR-3 call made inside the
 * ExpressionBundle services (runner, resolvers, service, cache) to an
 * additional sink (typically {@see MercureProgressLogger} wrapped in a
 * {@see TeeLogger}) for the duration of a single async evaluation — without
 * threading a reporter through every constructor.
 *
 * The default delegate is the autowired Monolog logger, so synchronous call
 * sites (API controllers, tests) see no behavioural change.
 */
final class LoggerSwitch extends AbstractLogger
{
    /** @var LoggerInterface[] */
    private array $stack = [];

    public function __construct(
        private readonly LoggerInterface $default = new NullLogger(),
    ) {}

    /** Push a logger that takes over until {@see pop()} is called. */
    public function push(LoggerInterface $logger): void
    {
        $this->stack[] = $logger;
    }

    /** Restore the previous delegate. */
    public function pop(): void
    {
        array_pop($this->stack);
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $delegate = end($this->stack) ?: $this->default;
        $delegate->log($level, $message, $context);
    }
}

