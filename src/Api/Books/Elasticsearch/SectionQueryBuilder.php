<?php

declare(strict_types=1);

namespace App\Api\Books\Elasticsearch;

use App\Api\Books\Dto\SectionSearchRequest;

final class SectionQueryBuilder
{
    /** @return array<string, mixed> */
    public function search(SectionSearchRequest $request): array
    {
        $variants = $this->variants($request->q);
        $should = [];
        foreach ($variants as $variant) {
            $should[] = ['match_phrase' => ['content' => ['query' => $variant, 'boost' => $request->quoted ? 20 : 8]]];
            foreach (['d', 'T', 'title'] as $tag) {
                $should[] = ['wildcard' => ['tags_flat.'.$tag => [
                    'value' => '*'.addcslashes($variant, '\\*?').'*',
                    'case_insensitive' => true,
                    'boost' => $request->quoted ? 16 : 5,
                ]]];
            }
        }

        $must = [];
        if (!$request->quoted) {
            $words = preg_split('/[\s-]+/', $request->q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (strlen($word) > 1) {
                    $must[] = ['match' => ['content' => ['query' => $word, 'operator' => 'and']]];
                }
            }
        }

        return [
            '_source' => BooksIndex::SOURCE_FIELDS,
            'size' => $request->limit,
            'query' => ['bool' => [
                'filter' => [['term' => ['kind' => 30041]]],
                'must' => $must,
                'should' => $should,
                'minimum_should_match' => $must === [] ? 1 : 0,
            ]],
            'sort' => [
                ['_score' => ['order' => 'desc']],
                ['created_at' => ['order' => 'desc']],
                ['id' => ['order' => 'asc']],
            ],
        ];
    }

    /** @return list<string> */
    private function variants(string $query): array
    {
        $space = preg_replace('/[\s-]+/', ' ', trim($query)) ?? $query;
        $hyphen = str_replace(' ', '-', $space);

        return array_values(array_unique([$space, $hyphen]));
    }
}
