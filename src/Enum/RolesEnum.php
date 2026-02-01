<?php

namespace App\Enum;

enum RolesEnum: string
{
    case USER = 'ROLE_USER';
    case ADMIN = 'ROLE_ADMIN';
    case EDITOR = 'ROLE_EDITOR';
    case FEATURED_WRITER = 'ROLE_FEATURED_WRITER';
    case MUTED = 'ROLE_MUTED';
    case ACTIVE_INDEXING = 'ROLE_ACTIVE_INDEXING';
}
