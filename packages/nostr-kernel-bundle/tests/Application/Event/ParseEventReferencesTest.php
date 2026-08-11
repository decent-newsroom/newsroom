<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Application\Event;

use DecentNewsroom\NostrKernelBundle\Application\Event\ParseEventReferences;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventReferenceType;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use PHPUnit\Framework\TestCase;

final class ParseEventReferencesTest extends TestCase
{
    private const PUBKEY = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testItParsesSupportedReferenceTags(): void
    {
        $parser = new ParseEventReferences();
        $event = new NostrEvent(
            kind: new EventKind(1),
            pubkey: new Pubkey(self::PUBKEY),
            tags: EventTags::fromRaw([
                ['e', 'event-ref'],
                ['p', self::PUBKEY],
                ['a', '30023:' . self::PUBKEY . ':slug'],
                ['t', 'nostr'],
                ['r', 'https://example.com'],
            ]),
        );

        $references = $parser->parse($event);

        self::assertCount(5, $references);
        self::assertSame(EventReferenceType::EVENT, $references[0]->type());
        self::assertSame(EventReferenceType::PUBKEY, $references[1]->type());
        self::assertSame(EventReferenceType::ADDRESSABLE_COORDINATE, $references[2]->type());
        self::assertSame(EventReferenceType::TOPIC, $references[3]->type());
        self::assertSame(EventReferenceType::EXTERNAL_URL, $references[4]->type());

        self::assertSame(['e', 'event-ref'], $references[0]->rawTag());
        self::assertSame(['r', 'https://example.com'], $references[4]->rawTag());
    }
}

