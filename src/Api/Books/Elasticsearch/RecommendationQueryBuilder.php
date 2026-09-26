<?php

declare(strict_types=1);

namespace App\Api\Books\Elasticsearch;

final class RecommendationQueryBuilder
{
    /**
     * @param list<string> $subjects
     * @param list<string> $authors
     * @param list<string> $excludeIds
     * @return array<string, mixed>
     */
    public function recommendation(array $subjects, array $authors, array $excludeIds, int $limit): array
    {
        $should = [];
        if ($subjects !== []) {
            $should[] = ['more_like_this' => [
                'fields' => ['tags_flat.t'],
                'like' => [['doc' => ['tags_flat' => ['t' => $subjects]]]],
                'min_term_freq' => 1,
                'min_doc_freq' => 1,
                'max_query_terms' => 25,
                'minimum_should_match' => 1,
                'boost' => 3,
            ]];
        }
        if ($authors !== []) {
            $should[] = ['constant_score' => [
                'filter' => ['terms' => ['tags_flat.N' => $authors]],
                'boost' => 1,
            ]];
        }

        $bool = [
            'filter' => [['term' => ['kind' => 30040]]],
            'should' => $should,
            'minimum_should_match' => 1,
        ];
        if ($excludeIds !== []) {
            $bool['must_not'] = [['terms' => ['id' => $excludeIds]]];
        }

        return [
            '_source' => BooksIndex::SOURCE_FIELDS,
            'size' => min(150, $limit * 3),
            'query' => $should === [] ? ['match_none' => new \stdClass()] : ['bool' => $bool],
            'sort' => [
                ['_score' => ['order' => 'desc']],
                ['id' => ['order' => 'asc']],
            ],
        ];
    }
}
