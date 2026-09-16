<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Logging;

use Psr\Log\AbstractLogger;

final class StderrLogger extends AbstractLogger
{
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $context['exception'] = $exception::class;
            $context['exception_message'] = $exception->getMessage();
        }

        $encodedContext = $context === [] ? '' : ' ' . json_encode(
            $context,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        fwrite(STDERR, sprintf(
            "[%s] %s %s%s\n",
            date(DATE_ATOM),
            strtoupper((string) $level),
            (string) $message,
            $encodedContext,
        ));
    }
}
