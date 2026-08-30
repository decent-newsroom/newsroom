<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfBookLoader;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClient;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BookshelfBookLoaderTest extends TestCase
{
    public function testItUsesTheLocalBooksApiWhenMercuryReturnsNoBooks(): void
    {
        $pubkey = str_repeat('a', 64);
        $reference = $this->coordinateReference($pubkey, 'book');
        $primaryClient = new MockHttpClient(new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR)));
        $localEvent = $this->publicationEvent($pubkey, 'book', 'Local book', 123, 'b');
        $localClient = new MockHttpClient(static function (string $method, string $url, array $options) use ($localEvent): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://php/books/api/events/filter', $url);
            $requestBody = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(100, $requestBody['limit']);

            return new MockResponse(json_encode([$localEvent], JSON_THROW_ON_ERROR));
        });

        $loader = new BookshelfBookLoader(
            new MercuryBookService(new MercuryApiClient($primaryClient, 'https://mercury.example')),
            $localClient,
            'http://php/books',
        );

        $books = $loader->getBooksForReferences([$reference]);

        self::assertSame('Local book', $books[0]['title']);
        self::assertSame(1, $primaryClient->getRequestsCount());
        self::assertSame(1, $localClient->getRequestsCount());
    }

    public function testItMergesBothSourcesInReferenceOrderAndKeepsTheNewestRevision(): void
    {
        $firstPubkey = str_repeat('a', 64);
        $secondPubkey = str_repeat('b', 64);
        $references = [
            $this->coordinateReference($firstPubkey, 'first'),
            $this->coordinateReference($secondPubkey, 'second'),
        ];
        $primaryClient = new MockHttpClient(new MockResponse(json_encode(['data' => [
            $this->publicationEvent($firstPubkey, 'first', 'Older Mercury title', 100, 'c'),
            $this->publicationEvent($secondPubkey, 'second', 'Mercury only', 200, 'd'),
        ]], JSON_THROW_ON_ERROR)));
        $localClient = new MockHttpClient(new MockResponse(json_encode([
            $this->publicationEvent($firstPubkey, 'first', 'Newer local title', 300, 'e'),
        ], JSON_THROW_ON_ERROR)));

        $loader = new BookshelfBookLoader(
            new MercuryBookService(new MercuryApiClient($primaryClient, 'https://mercury.example')),
            $localClient,
            'http://php/books',
        );

        $books = $loader->getBooksForReferences($references);

        self::assertCount(2, $books);
        self::assertSame(['Newer local title', 'Mercury only'], array_column($books, 'title'));
        self::assertSame([
            '30040:' . $firstPubkey . ':first',
            '30040:' . $secondPubkey . ':second',
        ], array_column($books, 'coordinate'));
    }

    public function testItUsesTheLocalBooksApiWhenMercuryIsUnavailable(): void
    {
        $pubkey = str_repeat('a', 64);
        $reference = $this->coordinateReference($pubkey, 'book');
        $primary = new MercuryBookService(new MercuryApiClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 503])),
            'https://mercury.example',
        ));
        $localClient = new MockHttpClient(new MockResponse(json_encode([
            $this->publicationEvent($pubkey, 'book', 'Local book', 123, 'b'),
        ], JSON_THROW_ON_ERROR)));

        $loader = new BookshelfBookLoader($primary, $localClient, 'http://php/books');

        $books = $loader->getBooksForReferences([$reference]);

        self::assertSame('Local book', $books[0]['title']);
        self::assertSame('30040:' . $pubkey . ':book', $books[0]['coordinate']);
    }

    public function testItAllowsTheDirectEsFallbackWhenTheOnlyAvailableHttpSourceIsEmpty(): void
    {
        $pubkey = str_repeat('a', 64);
        $primary = new MercuryBookService(new MercuryApiClient(
            new MockHttpClient(new MockResponse(json_encode(['data' => []], JSON_THROW_ON_ERROR))),
            'https://mercury.example',
        ));
        $localClient = new MockHttpClient(new MockResponse('', ['http_code' => 503]));
        $loader = new BookshelfBookLoader($primary, $localClient, 'http://php/books');

        $this->expectException(MercuryApiException::class);

        $loader->getBooksForReferences([$this->coordinateReference($pubkey, 'book')]);
    }

    /**
     * @return array{type: 'a', coordinate: string, relay: null, eventId: null, pubkey: string}
     */
    private function coordinateReference(string $pubkey, string $identifier): array
    {
        return [
            'type' => 'a',
            'coordinate' => '30040:' . $pubkey . ':' . $identifier,
            'relay' => null,
            'eventId' => null,
            'pubkey' => $pubkey,
        ];
    }

    /** @return array<string, mixed> */
    private function publicationEvent(
        string $pubkey,
        string $identifier,
        string $title,
        int $createdAt,
        string $idCharacter,
    ): array {
        return [
            'id' => str_repeat($idCharacter, 64),
            'kind' => 30040,
            'pubkey' => $pubkey,
            'created_at' => $createdAt,
            'content' => '',
            'sig' => str_repeat('f', 128),
            'tags' => [
                ['d', $identifier],
                ['title', $title],
                ['a', '30041:' . $pubkey . ':chapter-1'],
            ],
        ];
    }
}
