<?php

declare(strict_types=1);

namespace App\ChatBundle\Enum;

enum ChatGroupStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}

