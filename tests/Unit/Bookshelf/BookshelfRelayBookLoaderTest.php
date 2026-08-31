<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use App\Service\Nostr\NostrClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BookshelfRelayBookLoaderTest extends TestCase
{
    public function testItFillsOnlyTheDirectoryReferencesMissingFromTheBooksIndex(): void
    {
        $pubkey = str_repeat('a', 64);
        $indexedCoordinate = '30040:' . $pubkey . ':indexed-book';
        $nativeCoordinate = '30040:' . $pubkey . ':nostr-original';
        $relay = 'wss://books.example';
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventsByCoordinates')
            ->with([$nativeCoordinate], [$relay])
            ->willReturn([$nativeCoordinate => $this->publicationEvent($pubkey, 'nostr-original', 'Nostr original')]);
        $nostrClient->expects(self::never())->method('getEventsByIds');

        $loader = new BookshelfRelayBookLoader($nostrClient, $this->createStub(LoggerInterface::class));
        $books = $loader->fillMissingBooks([
            $this->reference($indexedCoordinate),
            $this->reference($nativeCoordinate, $relay),
        ], [[
            'id' => str_repeat('b', 64),
            'coordinate' => $indexedCoordinate,
            'title' => 'Indexed book',
            'relay' => null,
            'createdAt' => 10,
        ]]);

        self::assertSame(['Indexed book', 'Nostr original'], array_column($books, 'title'));
        self::assertSame($relay, $books[1]['relay']);
    }

    public function testItFetchesAnEventIdReferenceFromTheRegularRelayPath(): void
    {
        $pubkey = str_repeat('a', 64);
        $eventId = str_repeat('c', 64);
        $relay = 'wss://books.example';
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventsByIds')
            ->with([$eventId], [$relay])
            ->willReturn([$eventId => $this->publicationEvent($pubkey, 'event-id-book', 'Event ID original')]);
        $nostrClient->expects(self::never())->method('getEventsByCoordinates');

        $loader = new BookshelfRelayBookLoader($nostrClient, $this->createStub(LoggerInterface::class));
        $books = $loader->fillMissingBooks([[
            'type' => 'e',
            'coordinate' => null,
            'relay' => $relay,
            'eventId' => $eventId,
            'pubkey' => $pubkey,
        ]], []);

        self::assertSame('Event ID original', $books[0]['title']);
        self::assertSame($relay, $books[0]['relay']);
    }

    /** @return array{type: 'a', coordinate: string, relay: ?string, eventId: null, pubkey: string} */
    private function reference(string $coordinate, ?string $relay = null): array
    {
        [, $pubkey] = explode(':', $coordinate, 3);

        return [
            'type' => 'a',
            'coordinate' => $coordinate,
            'relay' => $relay,
            'eventId' => null,
            'pubkey' => $pubkey,
        ];
    }

    private function publicationEvent(string $pubkey, string $identifier, string $title): object
    {
        return (object) [
            'id' => str_repeat('c', 64),
            'kind' => 30040,
            'pubkey' => $pubkey,
            'created_at' => 20,
            'tags' => [
                ['d', $identifier],
                ['title', $title],
                ['a', '30041:' . $pubkey . ':chapter-1'],
            ],
        ];
    }
}
