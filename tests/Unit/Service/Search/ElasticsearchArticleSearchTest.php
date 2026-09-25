<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Dto\SearchFilters;
use App\Service\Search\ElasticsearchArticleSearch;
use Elastica\Query;
use FOS\ElasticaBundle\Finder\FinderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ElasticsearchArticleSearchTest extends TestCase
{
    public function testOldestFirstAndDateRangeUseInclusiveStartAndExclusiveNextDay(): void
    {
        $queries = [];
        $finder = $this->createMock(FinderInterface::class);
        $finder->expects(self::once())
            ->method('find')
            ->willReturnCallback(static function (Query $query) use (&$queries): array {
                $queries[] = $query->toArray();

                return [];
            });

        $service = new ElasticsearchArticleSearch($finder, new NullLogger());
        $service->advancedSearch('', new SearchFilters(
            dateFrom: '2026-09-01',
            dateTo: '2026-09-25',
            sortBy: 'oldest',
        ));

        self::assertCount(1, $queries);
        self::assertSame([['createdAt' => ['order' => 'asc']]], $queries[0]['sort']);
        $range = $queries[0]['query']['bool']['filter'][0]['range']['createdAt'];
        self::assertSame('2026-09-01T00:00:00Z', $range['gte']);
        self::assertSame('2026-09-26T00:00:00Z', $range['lt']);
        self::assertArrayNotHasKey('lte', $range);
        self::assertArrayHasKey('must', $queries[0]['query']['bool']);
    }

    public function testLiteralZeroAddsAFullTextQuery(): void
    {
        $captured = null;
        $finder = $this->createMock(FinderInterface::class);
        $finder->expects(self::once())
            ->method('find')
            ->willReturnCallback(static function (Query $query) use (&$captured): array {
                $captured = $query->toArray();

                return [];
            });

        $service = new ElasticsearchArticleSearch($finder, new NullLogger());
        $service->advancedSearch('0', new SearchFilters(sortBy: 'oldest'));

        self::assertNotNull($captured);
        self::assertSame('0', $captured['query']['bool']['must'][0]['multi_match']['query']);
    }
}
