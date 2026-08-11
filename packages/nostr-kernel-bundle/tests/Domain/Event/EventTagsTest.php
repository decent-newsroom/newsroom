<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use PHPUnit\Framework\TestCase;

final class EventTagsTest extends TestCase
{
    public function testItPreservesRawTagsAndExposesHelpers(): void
    {
        $raw = [
            ['d', 'article-slug'],
            ['e', 'event-1'],
            ['e', 'event-2', 'wss://relay.example'],
            ['p', 'pubkey-1'],
        ];

        $tags = EventTags::fromRaw($raw);

        self::assertSame('article-slug', $tags->dTag());
        self::assertSame('event-1', $tags->firstValue('e'));
        self::assertSame([
            ['e', 'event-1'],
            ['e', 'event-2', 'wss://relay.example'],
        ], $tags->allByName('e'));
        self::assertSame($raw, $tags->raw());
    }
}

