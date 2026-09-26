<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Elasticsearch\RecommendationQueryBuilder;
use PHPUnit\Framework\TestCase;

final class RecommendationQueryBuilderTest extends TestCase
{
    public function testMetadataSimilarityPreservesKeywordValuesAndExcludesNostrIds(): void
    {
        $ids = [str_repeat('a', 64), str_repeat('b', 64)];
        $query = (new RecommendationQueryBuilder())->recommendation(['Science fiction -- History'], ['Author, A.'], $ids, 10);
        $bool = $query['query']['bool'];

        self::assertSame([['term' => ['kind' => 30040]]], $bool['filter']);
        self::assertSame([['terms' => ['id' => $ids]]], $bool['must_not']);
        self::assertSame(1, $bool['minimum_should_match']);
        $mlt = $bool['should'][0]['more_like_this'];
        self::assertSame(['tags_flat.t'], $mlt['fields']);
        self::assertSame([['doc' => ['tags_flat' => ['t' => ['Science fiction -- History']]]]], $mlt['like']);
        self::assertSame(1, $mlt['min_term_freq']);
        self::assertSame(1, $mlt['min_doc_freq']);
        self::assertSame(1, $mlt['minimum_should_match']);
        self::assertSame(25, $mlt['max_query_terms']);
        self::assertSame(3, $mlt['boost']);
        self::assertSame(['constant_score' => ['filter' => ['terms' => ['tags_flat.N' => ['Author, A.']]], 'boost' => 1]], $bool['should'][1]);
        self::assertSame(30, $query['size']);
        self::assertSame([['_score' => ['order' => 'desc']], ['id' => ['order' => 'asc']]], $query['sort']);
    }

    public function testAuthorOnlyFallbackDoesNotConstructEmptyMlt(): void
    {
        $query = (new RecommendationQueryBuilder())->recommendation([], ['Author'], [], 50);
        self::assertCount(1, $query['query']['bool']['should']);
        self::assertArrayHasKey('constant_score', $query['query']['bool']['should'][0]);
        self::assertArrayNotHasKey('must_not', $query['query']['bool']);
        self::assertSame(150, $query['size']);
    }

    public function testSubjectsOnlyAndEmptySignals(): void
    {
        $builder = new RecommendationQueryBuilder();
        $subjectsOnly = $builder->recommendation(['History'], [], [], 1);
        self::assertCount(1, $subjectsOnly['query']['bool']['should']);
        self::assertArrayHasKey('more_like_this', $subjectsOnly['query']['bool']['should'][0]);
        self::assertSame(3, $subjectsOnly['size']);
        self::assertSame('{"match_none":{}}', json_encode($builder->recommendation([], [], [], 10)['query']));
    }
}
