<?php

declare(strict_types=1);

namespace App\ChatBundle\Enum;

enum ChatUserStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}

