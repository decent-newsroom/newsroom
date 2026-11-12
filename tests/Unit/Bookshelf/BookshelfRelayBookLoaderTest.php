<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use App\Service\Nostr\NostrClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BookshelfRelayBookLoaderTest extends TestCase
{
    public function testItRefreshesIndexedCoordinatesAsWellAsMissingBooks(): void
    {
        $pubkey = str_repeat('a', 64);
        $indexedCoordinate = '30040:' . $pubkey . ':indexed-book';
        $nativeCoordinate = '30040:' . $pubkey . ':nostr-original';
        $relay = 'wss://books.example';
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventsByCoordinates')
            ->with([$indexedCoordinate, $nativeCoordinate], [$relay], true)
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

    /** @dataProvider revisionCases */
    public function testItKeepsTheNewestRevision(int $timestamp, string $eventId, bool $replace): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = '30040:' . $pubkey . ':book';
        $replacement = $this->publicationEvent($pubkey, 'book', 'Replacement');
        $replacement->created_at = $timestamp;
        $replacement->id = $eventId;
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventsByCoordinates')
            ->with([$coordinate], [], true)->willReturn([$coordinate => $replacement]);
        $client->expects(self::never())->method('getEventsByIds');
        $books = (new BookshelfRelayBookLoader($client, $this->createStub(LoggerInterface::class)))
            ->fillMissingBooks([$this->reference($coordinate)], [[
                'id' => str_repeat('b', 64), 'coordinate' => $coordinate,
                'title' => 'Indexed', 'createdAt' => 20,
            ]]);
        self::assertSame($replace ? 'Replacement' : 'Indexed', $books[0]['title']);
        self::assertSame($replace ? $eventId : str_repeat('b', 64), $books[0]['id']);
    }

    public static function revisionCases(): iterable
    {
        yield 'newer relay revision' => [21, str_repeat('c', 64), true];
        yield 'older relay revision' => [19, str_repeat('c', 64), false];
        yield 'tie prefers lower id' => [20, str_repeat('a', 64), true];
        yield 'tie rejects higher id' => [20, str_repeat('c', 64), false];
        yield 'same revision retains indexed metadata' => [20, str_repeat('b', 64), false];
    }

    public function testRefreshFailurePreservesIndexedBookAndLogsWarning(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':book';
        $client = $this->createMock(NostrClient::class);
        $client->method('getEventsByCoordinates')->willThrowException(new \RuntimeException('Offline'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Could not fetch Nostr-native bookshelf books by coordinate.', self::isType('array'),
        );
        $indexed = ['id' => str_repeat('b', 64), 'coordinate' => $coordinate, 'title' => 'Indexed', 'createdAt' => 20];
        $books = (new BookshelfRelayBookLoader($client, $logger))->fillMissingBooks(
            [$this->reference($coordinate)], [$indexed],
        );
        self::assertSame([$indexed], $books);
    }

    /** @dataProvider pinnedCases */
    public function testPinsNeverFallBackToAnotherRevision(bool $resolved, bool $wrongCoordinate): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = '30040:' . $pubkey . ':book';
        $reference = $this->reference($coordinate);
        $reference['eventId'] = str_repeat('c', 64);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventsByCoordinates');
        $client->expects($resolved && !$wrongCoordinate ? self::never() : self::once())->method('getEventsByIds')
            ->willReturn([]);
        $indexed = [
            'id' => $resolved ? str_repeat('c', 64) : str_repeat('b', 64),
            'coordinate' => $wrongCoordinate ? $coordinate . '-other' : $coordinate,
            'title' => 'Pinned', 'createdAt' => 20,
        ];
        $books = (new BookshelfRelayBookLoader($client, $this->createStub(LoggerInterface::class)))
            ->fillMissingBooks([$reference], [$indexed]);
        self::assertSame($resolved && !$wrongCoordinate ? [$indexed] : [], $books);
    }

    public static function pinnedCases(): iterable
    {
        yield 'resolved pin' => [true, false];
        yield 'missing pin with different revision available' => [false, false];
        yield 'wrong coordinate for matching event id' => [true, true];
    }

    public function testResolvedExactEventReferenceDoesNotRefreshItsCoordinate(): void
    {
        $pubkey = str_repeat('a', 64);
        $id = str_repeat('b', 64);
        $indexed = [
            'id' => $id, 'coordinate' => '30040:' . $pubkey . ':book', 'title' => 'Exact', 'createdAt' => 20,
        ];
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventsByCoordinates');
        $client->expects(self::never())->method('getEventsByIds');
        $books = (new BookshelfRelayBookLoader($client, $this->createStub(LoggerInterface::class)))
            ->fillMissingBooks([[
                'type' => 'e', 'coordinate' => null, 'relay' => null, 'eventId' => $id, 'pubkey' => $pubkey,
            ]], [$indexed]);
        self::assertSame([$indexed], $books);
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
