<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Nip19;

enum Nip19Type: string
{
    case NPUB = 'npub';
    case NPROFILE = 'nprofile';
    case NOTE = 'note';
    case NEVENT = 'nevent';
    case NADDR = 'naddr';
    case NSEC = 'nsec';
}

