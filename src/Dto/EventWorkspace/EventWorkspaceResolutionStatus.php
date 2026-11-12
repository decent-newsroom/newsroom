<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

enum EventWorkspaceResolutionStatus: string
{
    case Available = 'available';
    case Missing = 'missing';
    case Loading = 'loading';
    case Unavailable = 'unavailable';
    case Restricted = 'restricted';
    case Invalid = 'invalid';
    case Failed = 'failed';
}
