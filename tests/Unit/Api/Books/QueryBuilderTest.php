<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Dto\Nip01Filter;
use App\Api\Books\Dto\PublicationSearchRequest;
use App\Api\Books\Dto\SectionSearchRequest;
use App\Api\Books\Elasticsearch\EventQueryBuilder;
use App\Api\Books\Elasticsearch\PublicationQueryBuilder;
use App\Api\Books\Elasticsearch\SectionQueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    public function testEventQueryUsesTheCaseSensitiveTagFieldAndStableSort(): void
    {
        $query = (new EventQueryBuilder())->filter(Nip01Filter::fromArray([
            'limit' => 5,
            'since' => 1,
            'until' => 2,
            '#T' => ['The Republic'],
        ]));

        self::assertSame(['terms' => ['tags_flat.T' => ['The Republic']]], $query['query']['bool']['filter'][1]);
        self::assertSame([['created_at' => ['order' => 'desc']], ['id' => ['order' => 'asc']]], $query['sort']);
    }

    public function testPublicationSearchUsesCanonicalUppercaseAuthorAndTitleTags(): void
    {
        $query = (new PublicationQueryBuilder())->search(PublicationSearchRequest::fromArray([
            'title' => 'Republic',
            'author' => 'Plato',
            'identifier' => 'https://example.test/book',
        ]));

        $filters = $query['query']['bool']['filter'];
        self::assertSame(['term' => ['kind' => 30040]], $filters[0]);
        self::assertArrayHasKey('tags_flat.T', $filters[1]['wildcard']);
        self::assertArrayHasKey('tags_flat.N', $filters[2]['wildcard']);
        self::assertArrayHasKey('tags_flat.s', $filters[3]['wildcard']);
    }

    public function testQuotedSectionSearchBoostsPhrases(): void
    {
        $query = (new SectionQueryBuilder())->search(SectionSearchRequest::fromArray(['q' => '"civil society"']));

        self::assertSame([], $query['query']['bool']['must']);
        self::assertSame(1, $query['query']['bool']['minimum_should_match']);
        self::assertSame(20, $query['query']['bool']['should'][0]['match_phrase']['content']['boost']);
    }

    public function testUnquotedSectionSearchRequiresAllWords(): void
    {
        $query = (new SectionQueryBuilder())->search(SectionSearchRequest::fromArray(['q' => 'civil society']));

        self::assertCount(2, $query['query']['bool']['must']);
        self::assertSame(0, $query['query']['bool']['minimum_should_match']);
    }
}
