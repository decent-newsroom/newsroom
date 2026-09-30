<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BooksChapterLookup;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class BooksChapterLookupTest extends TestCase
{
    public function testHydratesExactChapterAndCachesItWithoutPersisting(): void
    {
        $pubkey = str_repeat('a', 64);
        $wrong = $this->event($pubkey, 'other', 'Wrong');
        $chapter = $this->event($pubkey, 'intro', 'From Books API');
        $response = $this->response(200, [$wrong, $chapter]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')
            ->with('POST', 'https://books.example/books/api/events/filter', self::callback(
                static fn (array $options): bool => $options['json'] === [
                    'authors' => [$pubkey],
                    'kinds' => [30041],
                    '#d' => ['intro'],
                    'limit' => 10,
                ],
            ))
            ->willReturn($response);

        $lookup = new BooksChapterLookup($httpClient, 'https://books.example/', new ArrayAdapter(), new NullLogger());
        $first = $lookup->find($pubkey, 'intro');
        $second = $lookup->find($pubkey, 'intro');

        self::assertNotNull($first);
        self::assertSame('From Books API', $first->getTitle());
        self::assertSame('intro', $first->getDTag());
        self::assertSame($chapter['id'], $first->getId());
        self::assertNotSame($first, $second);
        self::assertSame($first->getId(), $second?->getId());
    }

    public function testMismatchedResponseIsNotHydrated(): void
    {
        $pubkey = str_repeat('b', 64);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($this->response(200, [
            $this->event(str_repeat('c', 64), 'intro', 'Wrong author'),
            $this->event($pubkey, 'other', 'Wrong chapter'),
        ]));

        $lookup = new BooksChapterLookup($httpClient, 'https://books.example', new ArrayAdapter(), new NullLogger());
        self::assertNull($lookup->find($pubkey, 'intro'));
    }

    public function testApiFailureReturnsNull(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->willReturn($this->response(503, []));
        $lookup = new BooksChapterLookup($httpClient, 'https://books.example', new ArrayAdapter(), new NullLogger());

        self::assertNull($lookup->find(str_repeat('d', 64), 'intro'));
        self::assertNull($lookup->find(str_repeat('c', 64), 'another-chapter'));
    }

    /** @return array<string, mixed> */
    private function event(string $pubkey, string $identifier, string $title): array
    {
        return [
            'id' => str_repeat('e', 64),
            'kind' => 30041,
            'pubkey' => $pubkey,
            'content' => '= ' . $title,
            'created_at' => 123,
            'tags' => [['d', $identifier], ['title', $title]],
            'sig' => str_repeat('f', 128),
        ];
    }

    /** @param list<array<string, mixed>> $events */
    private function response(int $status, array $events): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('toArray')->with(false)->willReturn($events);

        return $response;
    }
}
