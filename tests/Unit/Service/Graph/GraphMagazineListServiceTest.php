<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Graph;

use App\Service\Graph\GraphLookupService;
use App\Service\Graph\GraphMagazineListService;
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
        $this->service = new GraphMagazineListService($connection, $graphLookup, new NullLogger());
    }

    /**
     * @dataProvider topLevelMagazineProvider
     */
    public function testIsTopLevelMagazine(?array $eventRow, bool $expected, string $message): void
    {
        $method = new \ReflectionMethod($this->service, 'isTopLevelMagazine');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($this->service, $eventRow), $message);
    }

    public function topLevelMagazineProvider(): iterable
    {
        yield 'null event row' => [null, false, 'Null event row should not be a magazine'];

        yield 'event with type=magazine tag' => [
            ['tags' => json_encode([['type', 'magazine'], ['title', 'My Mag']])],
            true,
            'Event with type=magazine tag should be a magazine',
        ];

        yield 'event with only a-tag pointing to 30040 (category, not magazine)' => [
            ['tags' => json_encode([['a', '30040:' . str_repeat('ab', 32) . ':some-cat'], ['title', 'A Category']])],
            false,
            'Event with 30040 a-tag but no type=magazine should NOT be a magazine',
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

        yield 'event with empty tags' => [
            ['tags' => '[]'],
            false,
            'Event with empty tags should not be a magazine',
        ];
    }
}
