<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Domain\Projection;

enum ProjectionStatus: string
{
    case Skipped = 'skipped';
    case Projected = 'projected';
    case Failed = 'failed';
}
