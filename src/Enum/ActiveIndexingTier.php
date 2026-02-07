<?php

namespace App\Enum;

enum ActiveIndexingTier: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    public function getPriceInSats(): int
    {
        return match ($this) {
            self::MONTHLY => 1000,
            self::YEARLY => 10000,
        };
    }

    public function getDurationDays(): int
    {
        return match ($this) {
            self::MONTHLY => 30,
            self::YEARLY => 365,
        };
    }

    public function getGracePeriodDays(): int
    {
        return match ($this) {
            self::MONTHLY => 7,
            self::YEARLY => 30,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::MONTHLY => 'Monthly', // Monthly (1,000 sats)
            self::YEARLY => 'Yearly', // Yearly (10,000 sats)
        };
    }
}
