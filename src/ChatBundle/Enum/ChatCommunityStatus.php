<?php

declare(strict_types=1);

namespace App\ChatBundle\Enum;

enum ChatCommunityStatus: string
{
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
}

