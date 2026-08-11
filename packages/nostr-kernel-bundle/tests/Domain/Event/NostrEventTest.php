<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use PHPUnit\Framework\TestCase;

final class NostrEventTest extends TestCase
{
    public function testToArrayAllowsUnsignedEvents(): void
    {
        $event = new NostrEvent(
            kind: new EventKind(1),
            pubkey: new Pubkey(str_repeat('a', 64)),
            tags: EventTags::fromRaw([]),
        );

        self::assertNull($event->toArray()['id']);
        self::assertNull($event->toArray()['sig']);
    }
}
