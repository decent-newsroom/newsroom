<?php

declare(strict_types=1);

namespace App\Enum;

enum PublicationSubdomainStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Payment',
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function getBadgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-warning',
            self::ACTIVE => 'bg-success',
            self::EXPIRED => 'bg-danger',
            self::CANCELLED => 'bg-secondary',
        };
    }
}

