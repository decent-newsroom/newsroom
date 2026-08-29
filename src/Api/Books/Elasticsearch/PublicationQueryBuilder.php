<?php

declare(strict_types=1);

namespace App\Api\Books\Elasticsearch;

use App\Api\Books\Dto\PublicationSearchRequest;

final class PublicationQueryBuilder
{
    /** @return array<string, mixed> */
    public function search(PublicationSearchRequest $request): array
    {
        $filters = [['term' => ['kind' => 30040]]];
        foreach ([
            'title' => 'T',
            'author' => 'N',
            'subject' => 't',
        ] as $property => $tag) {
            if ($request->$property !== null) {
                $filters[] = $this->contains('tags_flat.'.$tag, $request->$property);
            }
        }
        if ($request->d !== null) {
            $filters[] = $this->contains('tags_flat.d', $this->hyphenVariant($request->d));
        }
        if ($request->language !== null) {
            $filters[] = ['term' => ['tags_flat.l' => ['value' => $request->language]]];
        }
        if ($request->identifier !== null) {
            $filters[] = $this->contains(
                str_starts_with(strtolower($request->identifier), 'http://') || str_starts_with(strtolower($request->identifier), 'https://')
                    ? 'tags_flat.s'
                    : 'tags_flat.i',
                $request->identifier,
            );
        }

        $bool = ['filter' => $filters];
        if ($request->q !== null) {
            $bool['should'] = $this->metadataQuery($request->q);
            $bool['minimum_should_match'] = 1;
        }

        return [
            '_source' => BooksIndex::SOURCE_FIELDS,
            'size' => $request->limit,
            'query' => ['bool' => $bool],
            'sort' => [
                ['_score' => ['order' => 'desc']],
                ['created_at' => ['order' => 'desc']],
                ['id' => ['order' => 'asc']],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function metadataQuery(string $query): array
    {
        $escaped = $this->escape($query);
        $prefix = $this->escape($this->hyphenVariant($query));
        $fields = ['N', 'T', 'd', 'i', 's', 't', 'title', 'author', 'identifier', 'source'];

        $clauses = [];
        foreach ($fields as $tag) {
            $field = 'tags_flat.'.$tag;
            $clauses[] = ['term' => [$field => ['value' => $query, 'boost' => 12]]];
            $clauses[] = ['wildcard' => [$field => ['value' => $prefix.'*', 'case_insensitive' => true, 'boost' => 5]]];
            $clauses[] = ['wildcard' => [$field => ['value' => '*'.$escaped.'*', 'case_insensitive' => true, 'boost' => 2]]];
        }

        return $clauses;
    }

    /** @return array<string, mixed> */
    private function contains(string $field, string $value): array
    {
        return ['wildcard' => [$field => [
            'value' => '*'.$this->escape($value).'*',
            'case_insensitive' => true,
        ]]];
    }

    private function escape(string $value): string
    {
        return addcslashes($value, '\\*?');
    }

    private function hyphenVariant(string $value): string
    {
        return preg_replace('/[\s-]+/', '-', trim($value)) ?? $value;
    }
}
