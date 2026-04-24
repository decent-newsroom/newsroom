<?php

namespace App\Enum;

enum VanityNamePaymentType: string
{
    case SUBSCRIPTION = 'subscription';   // @deprecated Recurring payment (no longer issued to users)
    case ONE_TIME = 'one_time';           // @deprecated Lifetime payment (no longer issued to users)
    case ADMIN_GRANTED = 'admin_granted'; // Free grant by admin
    case FREE = 'free';                   // Free self-registration (default for all new registrations)

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
            self::SUBSCRIPTION => 5000,    // @deprecated
            self::ONE_TIME => 100000,      // @deprecated
            self::ADMIN_GRANTED => 0,
            self::FREE => 0,
        };
    }

    public function getDurationInDays(): ?int
    {
        return match ($this) {
            self::SUBSCRIPTION => 90,      // @deprecated
            self::ONE_TIME => null,
            self::ADMIN_GRANTED => null,
            self::FREE => null,            // Lifetime
        };
    }
}

