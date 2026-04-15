<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\ExpressionBundle\Exception\InvalidArgumentException;

/**
 * Resolves relative time values to absolute Unix timestamps.
 */
final class TimeResolver
{
    private const UNITS = [
        's'  => 1,
        'm'  => 60,
        'h'  => 3600,
        'd'  => 86400,
        'w'  => 604800,
        'mo' => 2592000,   // 30 days
        'y'  => 31536000,  // 365 days
    ];

    public function resolve(string $value, int $now): int
    {
        if ($value === 'now') {
            return $now;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^(\d+)(s|m|h|d|w|mo|y)$/', $value, $m)) {
            return $now - ((int) $m[1] * self::UNITS[$m[2]]);
        }

        throw new InvalidArgumentException("Invalid time value: {$value}");
    }
}

