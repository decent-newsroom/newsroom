<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\Client\BooksApiClient;
use DecentNewsroom\Mcp\Tool\BookTools;
use PHPUnit\Framework\TestCase;

final class BookToolsTest extends TestCase
{
    public function testSearchBooksOmitsUnsetCriteria(): void
    {
        $client = $this->createMock(BooksApiClient::class);
        $client->expects($this->once())
            ->method('searchPublications')
            ->with(['q' => 'Republic', 'limit' => 5])
            ->willReturn([]);

        (new BookTools($client))->searchBooks(query: 'Republic', limit: 5);
    }

    public function testGetBookRejectsNonPublicationEvent(): void
    {
        $client = $this->createMock(BooksApiClient::class);
        $client->method('getBook')->willReturn(['id' => str_repeat('a', 64), 'kind' => 30041]);

        $result = (new BookTools($client))->getBook(str_repeat('a', 64));

        self::assertSame('Event is not a book', $result['error']);
    }
}
