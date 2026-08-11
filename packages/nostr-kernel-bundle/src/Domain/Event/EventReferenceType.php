<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

enum EventReferenceType: string
{
    case EVENT = 'event';
    case PUBKEY = 'pubkey';
    case ADDRESSABLE_COORDINATE = 'addressable_coordinate';
    case TOPIC = 'topic';
    case EXTERNAL_URL = 'external_url';
}

