<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\Client\BooksApiClient;
use DecentNewsroom\Mcp\Resource\BookResources;
use PHPUnit\Framework\TestCase;

final class BookResourcesTest extends TestCase
{
    public function testPercentEncodedEventIdIsDecodedBeforeLookup(): void
    {
        $eventId = str_repeat('a', 64);
        $client = $this->createMock(BooksApiClient::class);
        $client->expects($this->once())
            ->method('getBook')
            ->with($eventId)
            ->willReturn(['id' => $eventId, 'kind' => 30040]);

        $book = (new BookResources($client))->book(rawurlencode($eventId));

        self::assertSame($eventId, $book['id']);
    }
}
