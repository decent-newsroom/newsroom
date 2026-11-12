<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

enum EventWorkspaceIdentityType: string
{
    case EventId = 'event_id';
    case Address = 'address';
    case Profile = 'profile';
}
