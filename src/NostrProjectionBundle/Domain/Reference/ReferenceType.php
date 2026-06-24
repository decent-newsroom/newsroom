<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Domain\Reference;

enum ReferenceType: string
{
    case Event = 'event';
    case Pubkey = 'pubkey';
    case Addressable = 'addressable';
    case Topic = 'topic';
    case Url = 'url';
    case Unknown = 'unknown';
}
