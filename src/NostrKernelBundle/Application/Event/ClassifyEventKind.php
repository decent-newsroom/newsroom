<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Application\Event;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventKindClassifierInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;

final readonly class ClassifyEventKind implements EventKindClassifierInterface
{
    public function classify(int $kind): EventKind
    {
        return new EventKind($kind);
    }
}

