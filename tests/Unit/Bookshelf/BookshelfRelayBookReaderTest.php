<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfRelayBookLoader;
use App\Service\Nostr\NostrClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BookshelfRelayBookReaderTest extends TestCase
{
    public function testItLoadsNativeBookChaptersUsingTheIndexRelayHint(): void
    {
        $pubkey = str_repeat('a', 64);
        $bookId = str_repeat('b', 64);
        $chapterCoordinate = '30041:' . $pubkey . ':chapter-1';
        $relay = 'wss://books.example';
        $index = (object) [
            'id' => $bookId,
            'kind' => 30040,
            'pubkey' => $pubkey,
            'created_at' => 10,
            'tags' => [['d', 'native-book'], ['title', 'Native book'], ['a', $chapterCoordinate, $relay]],
        ];
        $chapter = (object) [
            'id' => str_repeat('c', 64),
            'kind' => 30041,
            'pubkey' => $pubkey,
            'created_at' => 20,
            'content' => '= Chapter one',
            'tags' => [['d', 'chapter-1'], ['title', 'Chapter one']],
        ];
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())->method('getEventsByIds')->with([$bookId], [])->willReturn([$bookId => $index]);
        $nostrClient->expects(self::once())->method('getEventsByCoordinates')->with([$chapterCoordinate], [$relay])->willReturn([$chapterCoordinate => $chapter]);

        $book = (new BookshelfRelayBookLoader($nostrClient, $this->createStub(LoggerInterface::class)))->getBook($bookId);

        self::assertSame('Native book', $book['title']);
        self::assertSame(1, $book['availableChapterCount']);
        self::assertSame('Chapter one', $book['chapters'][0]['title']);
        self::assertSame('= Chapter one', $book['chapters'][0]['content']);
    }
}
