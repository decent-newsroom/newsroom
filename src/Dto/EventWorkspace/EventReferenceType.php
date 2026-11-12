<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

enum EventReferenceType: string
{
    case Address = 'address';
    case EventId = 'event_id';
    case Profile = 'profile';
}
