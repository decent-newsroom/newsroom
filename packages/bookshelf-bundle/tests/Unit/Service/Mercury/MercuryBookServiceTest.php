<?php

declare(strict_types=1);

namespace DecentNewsroom\BookshelfBundle\Tests\Unit\Service\Mercury;

use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClient;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MercuryBookServiceTest extends TestCase
{
    public function testSearchKeepsOnlyBooksAndDeduplicatesReplaceableRevisions(): void
    {
        $pubkey = str_repeat('1', 64);
        $chapterId = str_repeat('a', 64);
        $old = $this->indexEvent(str_repeat('b', 64), $pubkey, 100, 'Old title', $chapterId);
        $new = $this->indexEvent(str_repeat('c', 64), $pubkey, 200, 'New title', $chapterId);
        $magazine = [
            'id' => str_repeat('d', 64),
            'kind' => 30040,
            'pubkey' => $pubkey,
            'created_at' => 300,
            'tags' => [
                ['d', 'magazine'],
                ['title', 'Not a book'],
                ['a', '30023:' . $pubkey . ':an-article'],
            ],
        ];

        $client = new MercuryApiClient(
            new MockHttpClient(new MockResponse(json_encode([
                'data' => [$old, $magazine, $new],
            ], JSON_THROW_ON_ERROR))),
            'https://mercury.example',
        );
        $service = new MercuryBookService($client);

        $books = $service->search('fables');

        self::assertCount(1, $books);
        self::assertSame('New title', $books[0]['title']);
        self::assertSame('https://covers.example/aesop.jpg', $books[0]['coverImage']);
        self::assertSame(1, $books[0]['chapterCount']);
        self::assertArrayNotHasKey('chapterRefs', $books[0]);
    }

    public function testBookChaptersFollowIndexOrderInsteadOfMercuryOrder(): void
    {
        $pubkey = str_repeat('2', 64);
        $indexId = str_repeat('3', 64);
        $firstId = str_repeat('4', 64);
        $secondId = str_repeat('5', 64);
        $index = $this->indexEvent($indexId, $pubkey, 300, 'Ordered book', $firstId, $secondId);

        $secondChapter = $this->chapterEvent($secondId, $pubkey, 'chapter-two', 'Second');
        $firstChapter = $this->chapterEvent($firstId, $pubkey, 'chapter-one', 'First');

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['data' => $index], JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                // Mercury filter responses are newest-first, not index-first.
                'data' => [$secondChapter, $firstChapter],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $service = new MercuryBookService(new MercuryApiClient($httpClient, 'https://mercury.example'));

        $book = $service->getBook($indexId);

        self::assertNotNull($book);
        self::assertSame(['First', 'Second'], array_column($book['chapters'], 'title'));
        self::assertSame([1, 2], array_column($book['chapters'], 'position'));
        self::assertSame(2, $book['availableChapterCount']);
        self::assertSame(0, $book['missingChapterCount']);
    }

    public function testSearchLeavesCoverImageNullWhenImageTagIsMissing(): void
    {
        $pubkey = str_repeat('8', 64);
        $chapterId = str_repeat('9', 64);
        $index = [
            'id' => str_repeat('a', 64),
            'kind' => 30040,
            'pubkey' => $pubkey,
            'created_at' => 123,
            'tags' => [
                ['d', 'imeta-book'],
                ['title', 'Imeta book'],
                ['a', sprintf('30041:%s:chapter-1', $pubkey), 'wss://relay.example', $chapterId],
            ],
        ];

        $client = new MercuryApiClient(
            new MockHttpClient(new MockResponse(json_encode([
                'data' => [$index],
            ], JSON_THROW_ON_ERROR))),
            'https://mercury.example',
        );
        $service = new MercuryBookService($client);

        $books = $service->search('imeta');

        self::assertCount(1, $books);
        self::assertNull($books[0]['coverImage']);
    }

    /**
     * @return array<string, mixed>
     */
    private function indexEvent(
        string $id,
        string $pubkey,
        int $createdAt,
        string $title,
        string ...$chapterIds,
    ): array {
        $tags = [
            ['d', 'aesop-fables'],
            ['title', $title],
            ['author', 'Aesop'],
            ['image', 'https://covers.example/aesop.jpg'],
            ['type', 'book'],
        ];

        foreach ($chapterIds as $index => $chapterId) {
            $tags[] = [
                'a',
                sprintf('30041:%s:chapter-%s', $pubkey, $index + 1),
                'wss://relay.example',
                $chapterId,
            ];
        }

        return [
            'id' => $id,
            'kind' => 30040,
            'pubkey' => $pubkey,
            'content' => '',
            'tags' => $tags,
            'created_at' => $createdAt,
            'sig' => str_repeat('6', 128),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chapterEvent(string $id, string $pubkey, string $identifier, string $title): array
    {
        return [
            'id' => $id,
            'kind' => 30041,
            'pubkey' => $pubkey,
            'content' => $title . ' content',
            'tags' => [
                ['d', $identifier],
                ['title', $title],
            ],
            'created_at' => $title === 'Second' ? 200 : 100,
            'sig' => str_repeat('7', 128),
        ];
    }
}
