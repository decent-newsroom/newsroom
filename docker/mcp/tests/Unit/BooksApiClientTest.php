<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\Client\BooksApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BooksApiClientTest extends TestCase
{
    public function testPublicationSearchPostsCriteriaToBooksApi(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode([['id' => 'book-id', 'kind' => 30040]]));
        });

        $client = new BooksApiClient($http, 'http://php');
        $books = $client->searchPublications(['q' => 'Republic', 'limit' => 5]);

        self::assertSame([['id' => 'book-id', 'kind' => 30040]], $books);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/books/api/publications/search', $captured['url']);
    }

    public function testGetBookReturnsNullOn404(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        $client = new BooksApiClient($http, 'http://php');

        self::assertNull($client->getBook(str_repeat('a', 64)));
    }
}
