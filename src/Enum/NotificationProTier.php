<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Paid tiers that grant {@see RolesEnum::NOTIFICATIONS_PRO}, unlocking the
 * heavier notification source types (NIP-51 set expansion) and higher
 * per-user subscription caps.
 *
 * Cheaper than Active Indexing: one HTTP/DB lookup per incoming event, not a
 * persistent relay fetch loop.
 */
enum NotificationProTier: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    public function getPriceInSats(): int
    {
        return match ($this) {
            self::MONTHLY => 500,
            self::YEARLY => 5000,
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
            self::MONTHLY => 'Monthly',
            self::YEARLY => 'Yearly',
        };
    }
}

