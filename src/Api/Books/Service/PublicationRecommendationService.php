<?php

declare(strict_types=1);

namespace App\Api\Books\Service;

use App\Api\Books\Dto\PublicationRecommendationRequest;
use App\Api\Books\Elasticsearch\BooksIndex;
use App\Api\Books\Elasticsearch\EventQueryBuilder;
use App\Api\Books\Elasticsearch\RecommendationQueryBuilder;
use App\Api\Books\Http\ApiException;
use App\Api\Books\Presenter\NostrEventPresenter;

final class PublicationRecommendationService
{
    public function __construct(
        private readonly BooksIndex $index,
        private readonly EventQueryBuilder $eventQueryBuilder,
        private readonly RecommendationQueryBuilder $recommendationQueryBuilder,
        private readonly NostrEventPresenter $presenter,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function recommend(PublicationRecommendationRequest $request): array
    {
        $hits = $this->index->search('publications.recommendations.seed', $this->eventQueryBuilder->eventById($request->seedEventId));
        $seed = isset($hits[0]) ? $this->presenter->present($hits[0]['source'], $hits[0]['id']) : null;
        if ($seed === null) {
            throw new ApiException(404, [], 'Event not found');
        }
        if ($seed['kind'] !== 30040) {
            throw new ApiException(400, ['seed_event_id must identify a kind 30040 publication']);
        }

        $subjects = $this->tagValues($seed['tags'], 't', 50);
        $authors = $this->tagValues($seed['tags'], 'N', 10);
        if ($subjects === [] && $authors === []) {
            return [];
        }

        $excludedIds = array_values(array_unique([$request->seedEventId, ...$request->excludeIds]));
        $query = $this->recommendationQueryBuilder->recommendation($subjects, $authors, $excludedIds, $request->limit);
        $candidates = $this->index->search('publications.recommendations', $query);
        $seenCoordinates = [$this->identity($seed) => true];
        $seenIds = array_fill_keys($excludedIds, true);
        $events = [];
        foreach ($candidates as $hit) {
            $event = $this->presenter->present($hit['source'], $hit['id']);
            if ($event === null || $event['kind'] !== 30040 || isset($seenIds[$event['id']])) {
                continue;
            }
            $identity = $this->identity($event);
            if (isset($seenCoordinates[$identity])) {
                continue;
            }
            $seenCoordinates[$identity] = true;
            $seenIds[$event['id']] = true;
            $events[] = $event;
            if (count($events) >= $request->limit) {
                break;
            }
        }

        return $events;
    }

    /**
     * @param list<list<string>> $tags
     * @return list<string>
     */
    private function tagValues(array $tags, string $name, int $limit): array
    {
        $values = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== $name || !isset($tag[1]) || trim($tag[1]) === '' || in_array($tag[1], $values, true)) {
                continue;
            }
            // Catalogue placeholders do not identify a shared author.
            if ($name === 'N' && in_array(strtolower(trim($tag[1])), ['anonymous', 'unknown'], true)) {
                continue;
            }
            $values[] = $tag[1];
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $event */
    private function identity(array $event): string
    {
        foreach ($event['tags'] as $tag) {
            if (($tag[0] ?? null) === 'd' && isset($tag[1])) {
                return 'coordinate:'.$event['kind'].':'.$event['pubkey'].':'.$tag[1];
            }
        }

        return 'event:'.$event['id'];
    }
}
