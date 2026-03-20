<?php

declare(strict_types=1);

namespace App\ChatBundle\Enum;

enum ChatRole: string
{
    case ADMIN = 'admin';
    case GUARDIAN = 'guardian';
    case USER = 'user';
}

