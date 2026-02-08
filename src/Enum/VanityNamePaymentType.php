<?php

namespace App\Enum;

enum VanityNamePaymentType: string
{
    case SUBSCRIPTION = 'subscription';   // Monthly recurring payment
    case ONE_TIME = 'one_time';           // Lifetime payment
    case ADMIN_GRANTED = 'admin_granted'; // Free grant by admin

    public function isLifetime(): bool
    {
        return $this === self::ONE_TIME || $this === self::ADMIN_GRANTED;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SUBSCRIPTION => 'Monthly Subscription',
            self::ONE_TIME => 'Lifetime (One-time)',
            self::ADMIN_GRANTED => 'Admin Granted',
        };
    }

    public function getPriceInSats(): int
    {
        return match ($this) {
            self::SUBSCRIPTION => 5000,    // 5,000 sats per month
            self::ONE_TIME => 100000,      // 100,000 sats lifetime
            self::ADMIN_GRANTED => 0,       // Free
        };
    }

    public function getDurationInDays(): ?int
    {
        return match ($this) {
            self::SUBSCRIPTION => 30,      // 30 days
            self::ONE_TIME => null,        // Lifetime
            self::ADMIN_GRANTED => null,   // Lifetime
        };
    }
}

