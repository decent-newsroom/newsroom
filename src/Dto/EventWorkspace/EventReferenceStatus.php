<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

enum EventReferenceStatus: string
{
    // Syntactically usable, but no target lookup has been performed.
    case Unresolved = 'unresolved';
    case Invalid = 'invalid';

    // A publication/directory a[3] is neither empty nor a valid event ID.
    // It may be a malformed version or a legacy/opaque field; do not assume latest.
    case AmbiguousVersion = 'ambiguous_version';
}
