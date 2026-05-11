<?php

namespace App\Enum;

enum VanityNamePaymentType: string
{
    case SUBSCRIPTION = 'subscription';   // Recurring 3-month payment
    case ONE_TIME = 'one_time';           // Lifetime one-time payment
    case ADMIN_GRANTED = 'admin_granted'; // Free grant by admin
    case FREE = 'free';                   // @deprecated Legacy free self-registration (no longer issued to users)

    public function isLifetime(): bool
    {
        return $this === self::ONE_TIME || $this === self::ADMIN_GRANTED || $this === self::FREE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SUBSCRIPTION => '3-Month Subscription',
            self::ONE_TIME => 'Lifetime (One-time)',
            self::ADMIN_GRANTED => 'Admin Granted',
            self::FREE => 'Free',
        };
    }

    public function getPriceInSats(): int
    {
        return match ($this) {
            self::SUBSCRIPTION => 5000,    // 5,000 sats per quarter
            self::ONE_TIME => 100000,      // 100,000 sats lifetime
            self::ADMIN_GRANTED => 0,
            self::FREE => 0,
        };
    }

    public function getDurationInDays(): ?int
    {
        return match ($this) {
            self::SUBSCRIPTION => 90,      // 3 months per payment
            self::ONE_TIME => null,
            self::ADMIN_GRANTED => null,
            self::FREE => null,
        };
    }
}

