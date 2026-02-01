<?php

namespace App\Enum;

enum ActiveIndexingStatus: string
{
    case PENDING = 'pending';    // Invoice generated, awaiting payment
    case ACTIVE = 'active';      // Subscription active
    case GRACE = 'grace';        // Expired but within grace period
    case EXPIRED = 'expired';    // Grace period ended

    public function isActive(): bool
    {
        return $this === self::ACTIVE || $this === self::GRACE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Payment',
            self::ACTIVE => 'Active',
            self::GRACE => 'Grace Period',
            self::EXPIRED => 'Expired',
        };
    }

    public function getBadgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-warning',
            self::ACTIVE => 'bg-success',
            self::GRACE => 'bg-warning',
            self::EXPIRED => 'bg-secondary',
        };
    }
}
