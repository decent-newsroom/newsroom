<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Contract\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;

interface EventKindClassifierInterface
{
    public function classify(int $kind): EventKind;
}

