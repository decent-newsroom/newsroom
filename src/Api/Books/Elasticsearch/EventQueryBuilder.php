<?php

declare(strict_types=1);

namespace App\Api\Books\Elasticsearch;

use App\Api\Books\Dto\Nip01Filter;

final class EventQueryBuilder
{
    /** @return array<string, mixed> */
    public function filter(Nip01Filter $filter): array
    {
        $clauses = [];
        if ($filter->ids !== []) {
            $clauses[] = ['terms' => ['id' => $filter->ids]];
        }
        if ($filter->authors !== []) {
            $clauses[] = ['terms' => ['pubkey' => $filter->authors]];
        }
        if ($filter->kinds !== []) {
            $clauses[] = ['terms' => ['kind' => $filter->kinds]];
        }
        if ($filter->since !== null || $filter->until !== null) {
            $range = [];
            if ($filter->since !== null) {
                $range['gte'] = $filter->since;
            }
            if ($filter->until !== null) {
                $range['lte'] = $filter->until;
            }
            $clauses[] = ['range' => ['created_at' => $range]];
        }
        foreach ($filter->tags as $tag => $values) {
            $clauses[] = ['terms' => ['tags_flat.'.substr($tag, 1) => $values]];
        }

        return [
            '_source' => BooksIndex::SOURCE_FIELDS,
            'size' => $filter->limit,
            'query' => ['bool' => ['filter' => $clauses]],
            'sort' => [
                ['created_at' => ['order' => 'desc']],
                ['id' => ['order' => 'asc']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function eventById(string $eventId): array
    {
        return [
            '_source' => BooksIndex::SOURCE_FIELDS,
            'size' => 1,
            'query' => ['term' => ['id' => ['value' => $eventId]]],
            'sort' => [['id' => ['order' => 'asc']]],
        ];
    }
}
