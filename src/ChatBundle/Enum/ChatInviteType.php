<?php

declare(strict_types=1);

namespace App\ChatBundle\Enum;

enum ChatInviteType: string
{
    case ACTIVATION = 'activation';
    case GROUP_JOIN = 'group_join';
}

