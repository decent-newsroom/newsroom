<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Graph;

use App\Repository\HiddenCoordinateRepository;
use App\Service\Graph\GraphLookupService;
use App\Service\Graph\GraphMagazineListService;
use App\Service\MutedPubkeysService;
use App\Service\Magazine\PublicationIndexClassifier;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GraphMagazineListServiceTest extends TestCase
{
    private GraphMagazineListService $service;

    protected function setUp(): void
    {
        $connection = $this->createMock(Connection::class);
        $graphLookup = $this->createMock(GraphLookupService::class);
        $hiddenCoordinateRepository = $this->createMock(HiddenCoordinateRepository::class);
        $mutedPubkeysService = $this->createMock(MutedPubkeysService::class);
        $this->service = new GraphMagazineListService(
            $connection,
            $graphLookup,
            $hiddenCoordinateRepository,
            $mutedPubkeysService,
            new PublicationIndexClassifier(),
            new NullLogger(),
        );
    }

    /**
     * @dataProvider topLevelMagazineProvider
     */
    public function testIsTopLevelMagazine(?array $eventRow, bool $expected, string $message): void
    {
        $method = new \ReflectionMethod($this->service, 'isTopLevelMagazine');

        $this->assertSame($expected, $method->invoke($this->service, $eventRow), $message);
    }

    public function topLevelMagazineProvider(): iterable
    {
        yield 'null event row' => [null, false, 'Null event row should not be a magazine'];

        yield 'event with type=magazine tag' => [
            ['tags' => json_encode([['type', 'magazine'], ['title', 'My Mag']])],
            false,
            'Event with type=magazine tag but no 30040 a-tags should NOT be a magazine',
        ];

        yield 'event with only a-tag pointing to 30040 (sub-index)' => [
            ['tags' => json_encode([['a', '30040:' . str_repeat('ab', 32) . ':some-cat'], ['title', 'A Category']])],
            true,
            'Event with 30040 a-tag should be a top-level magazine',
        ];

        yield 'event with a-tag pointing to articles only' => [
            ['tags' => json_encode([['a', '30023:' . str_repeat('ab', 32) . ':some-article'], ['title', 'A List']])],
            false,
            'Event with only article a-tags should not be a magazine',
        ];

        yield 'event with no relevant tags' => [
            ['tags' => json_encode([['d', 'some-slug'], ['title', 'Random Index']])],
            false,
            'Event without type=magazine should not be a magazine',
        ];

        yield 'event with type=magazine and child 30040 refs' => [
            ['tags' => json_encode([['type', 'magazine'], ['a', '30040:' . str_repeat('ab', 32) . ':cat-1']])],
            true,
            'Magazine with categories should be a magazine',
        ];

        yield 'event with child 30040 and 30041 refs' => [
            ['tags' => json_encode([
                ['a', '30040:' . str_repeat('ab', 32) . ':section-1'],
                ['a', '30041:' . str_repeat('cd', 32) . ':chapter-1'],
            ])],
            false,
            'An index containing chapter refs is a book, even when it also has 30040 refs',
        ];

        yield 'event with empty tags' => [
            ['tags' => '[]'],
            false,
            'Event with empty tags should not be a magazine',
        ];
    }

    /**
     * @dataProvider bookProvider
     */
    public function testIsBook(?array $eventRow, bool $expected): void
    {
        $method = new \ReflectionMethod($this->service, 'isBook');

        $this->assertSame($expected, $method->invoke($this->service, $eventRow));
    }

    public function bookProvider(): iterable
    {
        yield 'library card' => [
            ['tags' => json_encode([['d', 'book'], ['title', 'Book'], ['i', 'doi:10.1234/example']])],
            true,
        ];
        yield 'chapter index' => [
            ['tags' => json_encode([['a', '30041:' . str_repeat('ab', 32) . ':chapter-1']])],
            true,
        ];
        yield 'magazine section index' => [
            ['tags' => json_encode([['a', '30040:' . str_repeat('ab', 32) . ':section-1']])],
            false,
        ];
    }

    public function testAllBooksExcludeMutedPublishersBeforeEventHydration(): void
    {
        $mutedPubkey = str_repeat('a', 64);
        $visiblePubkey = str_repeat('b', 64);
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                [
                    'coord' => '30040:' . $mutedPubkey . ':muted-book',
                    'current_event_id' => 'muted-event',
                    'pubkey' => $mutedPubkey,
                    'd_tag' => 'muted-book',
                    'current_created_at' => 2,
                ],
                [
                    'coord' => '30040:' . $visiblePubkey . ':visible-book',
                    'current_event_id' => 'visible-event',
                    'pubkey' => $visiblePubkey,
                    'd_tag' => 'visible-book',
                    'current_created_at' => 1,
                ],
            ]);

        $graphLookup = $this->createMock(GraphLookupService::class);
        $graphLookup
            ->expects($this->once())
            ->method('fetchEventRows')
            ->with(['visible-event'])
            ->willReturn([
                'visible-event' => [
                    'tags' => json_encode([
                        ['d', 'visible-book'],
                        ['title', 'Visible Book'],
                    ]),
                ],
            ]);

        $hiddenCoordinateRepository = $this->createMock(HiddenCoordinateRepository::class);
        $hiddenCoordinateRepository
            ->method('findAllCoordinates')
            ->willReturn([]);
        $mutedPubkeysService = $this->createMock(MutedPubkeysService::class);
        $mutedPubkeysService
            ->expects($this->once())
            ->method('getMutedPubkeys')
            ->willReturn([$mutedPubkey]);

        $service = new GraphMagazineListService(
            $connection,
            $graphLookup,
            $hiddenCoordinateRepository,
            $mutedPubkeysService,
            new PublicationIndexClassifier(),
            new NullLogger(),
        );

        $books = $service->listAllBooks();

        $this->assertCount(1, $books);
        $this->assertSame('visible-book', $books[0]['slug']);
    }
}
