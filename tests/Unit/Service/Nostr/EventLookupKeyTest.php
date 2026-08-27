<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\EventLookupKey;
use PHPUnit\Framework\TestCase;

final class EventLookupKeyTest extends TestCase
{
    public function testNeventKeyKeepsExistingFormat(): void
    {
        $eventId = str_repeat('a', 64);

        self::assertSame('nevent:' . $eventId, EventLookupKey::forNevent($eventId));
        self::assertSame('/event-fetch/nevent:' . $eventId, EventLookupKey::topic(EventLookupKey::forNevent($eventId)));
    }

    public function testNaddrKeyKeepsExistingFormat(): void
    {
        $pubkey = str_repeat('b', 64);

        self::assertSame(
            'naddr:30023:' . $pubkey . ':article-slug',
            EventLookupKey::forNaddr(30023, $pubkey, 'article-slug'),
        );
        self::assertSame(
            '/event-fetch/naddr:30023:' . $pubkey . ':article-slug',
            EventLookupKey::topic(EventLookupKey::forNaddr(30023, $pubkey, 'article-slug')),
        );
    }
}
