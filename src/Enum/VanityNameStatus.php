<?php

namespace App\Enum;

enum VanityNameStatus: string
{
    case PENDING = 'pending';       // Awaiting payment confirmation
    case ACTIVE = 'active';         // Vanity name is active and can be used
    case SUSPENDED = 'suspended';   // Temporarily suspended
    case RELEASED = 'released';     // User released or it expired

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Payment',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::RELEASED => 'Released',
        };
    }

    public function getBadgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-warning',
            self::ACTIVE => 'bg-success',
            self::SUSPENDED => 'bg-danger',
            self::RELEASED => 'bg-secondary',
        };
    }
}

