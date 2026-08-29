<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfBookLoader;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClient;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BookshelfBookLoaderTest extends TestCase
{
    public function testItUsesTheLocalBooksApiWhenMercuryIsUnavailable(): void
    {
        $pubkey = str_repeat('a', 64);
        $reference = [
            'type' => 'a',
            'coordinate' => '30040:' . $pubkey . ':book',
            'relay' => null,
            'eventId' => null,
            'pubkey' => $pubkey,
        ];
        $primary = new MercuryBookService(new MercuryApiClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 503])),
            'https://mercury.example',
        ));
        $localClient = new MockHttpClient(new MockResponse(json_encode([
            'data' => [[
                'id' => str_repeat('b', 64),
                'kind' => 30040,
                'pubkey' => $pubkey,
                'created_at' => 123,
                'content' => '',
                'sig' => str_repeat('c', 128),
                'tags' => [
                    ['d', 'book'],
                    ['title', 'Local book'],
                    ['a', '30041:' . $pubkey . ':chapter-1'],
                ],
            ]],
        ], JSON_THROW_ON_ERROR)));

        $loader = new BookshelfBookLoader($primary, $localClient, 'http://php/books');

        $books = $loader->getBooksForReferences([$reference]);

        self::assertSame('Local book', $books[0]['title']);
        self::assertSame('30040:' . $pubkey . ':book', $books[0]['coordinate']);
    }
}
