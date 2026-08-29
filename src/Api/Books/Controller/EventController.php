<?php

declare(strict_types=1);

namespace App\Api\Books\Controller;

use App\Api\Books\Dto\Nip01Filter;
use App\Api\Books\Elasticsearch\BooksIndex;
use App\Api\Books\Elasticsearch\EventQueryBuilder;
use App\Api\Books\Http\ApiException;
use App\Api\Books\Http\RequestDecoder;
use App\Api\Books\Presenter\NostrEventPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/events')]
final class EventController
{
    public function __construct(
        private readonly RequestDecoder $decoder,
        private readonly EventQueryBuilder $queryBuilder,
        private readonly BooksIndex $index,
        private readonly NostrEventPresenter $presenter,
    ) {
    }

    #[Route('', name: 'events_query', methods: ['GET'])]
    public function query(Request $request): JsonResponse
    {
        try {
            $filter = Nip01Filter::fromArray($this->queryInput($this->decoder->repeatedQuery($request)), true);

            return $this->json($this->present($this->index->search('events.query', $this->queryBuilder->filter($filter))));
        } catch (ApiException $exception) {
            return $this->error($exception);
        }
    }

    #[Route('/filter', name: 'events_filter', methods: ['POST'], priority: 10)]
    public function filter(Request $request): JsonResponse
    {
        try {
            $filter = Nip01Filter::fromArray($this->decoder->jsonObject($request));

            return $this->json($this->present($this->index->search('events.filter', $this->queryBuilder->filter($filter))));
        } catch (ApiException $exception) {
            return $this->error($exception);
        }
    }

    #[Route('/{eventId}', name: 'event_show', methods: ['GET'])]
    public function show(string $eventId): JsonResponse
    {
        if (preg_match('/^[A-Fa-f0-9]{64}$/', $eventId) !== 1) {
            return $this->error(new ApiException(400, ['event_id must be a 64-character hexadecimal ID']));
        }

        try {
            $events = $this->present($this->index->search('events.show', $this->queryBuilder->eventById($eventId)));
            if ($events === []) {
                return $this->json(['error' => 'Event not found'], 404);
            }

            return $this->json($events[0]);
        } catch (ApiException $exception) {
            return $this->error($exception);
        }
    }

    /**
     * @param array<string, string|list<string>> $query
     * @return array<string, mixed>
     */
    private function queryInput(array $query): array
    {
        $input = [];
        foreach ($query as $key => $value) {
            $isList = in_array($key, ['ids', 'authors', 'kinds'], true) || preg_match('/^#[A-Za-z]$/', $key) === 1;
            if ($isList) {
                $values = is_array($value) ? $value : [$value];
                if ($key === 'kinds') {
                    $values = array_merge(...array_map(
                        static fn (string $kind): array => array_map(trim(...), explode(',', $kind)),
                        $values,
                    ));
                }
                $input[$key] = $values;
                continue;
            }
            if (is_array($value)) {
                throw new ApiException(400, [sprintf('%s may only be supplied once', $key)]);
            }
            $input[$key] = $value;
        }

        return $input;
    }

    /** @param list<array{id: string, source: array<string, mixed>}> $hits
     *  @return list<array<string, mixed>>
     */
    private function present(array $hits): array
    {
        $events = [];
        foreach ($hits as $hit) {
            $event = $this->presenter->present($hit['source'], $hit['id']);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    private function error(ApiException $exception): JsonResponse
    {
        return $this->json(['error' => $exception->getMessage(), 'details' => $exception->details()], $exception->status());
    }
}
