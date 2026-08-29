<?php

declare(strict_types=1);

namespace App\Api\Books\Controller;

use App\Api\Books\Dto\PublicationSearchRequest;
use App\Api\Books\Dto\SectionSearchRequest;
use App\Api\Books\Elasticsearch\BooksIndex;
use App\Api\Books\Elasticsearch\PublicationQueryBuilder;
use App\Api\Books\Elasticsearch\SectionQueryBuilder;
use App\Api\Books\Http\ApiException;
use App\Api\Books\Http\RequestDecoder;
use App\Api\Books\Presenter\NostrEventPresenter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/publications')]
final class PublicationController
{
    public function __construct(
        private readonly RequestDecoder $decoder,
        private readonly PublicationQueryBuilder $publicationQueryBuilder,
        private readonly SectionQueryBuilder $sectionQueryBuilder,
        private readonly BooksIndex $index,
        private readonly NostrEventPresenter $presenter,
    ) {
    }

    #[Route('/search', name: 'publications_search', methods: ['POST'])]
    public function search(Request $request): JsonResponse
    {
        try {
            $search = PublicationSearchRequest::fromArray($this->decoder->jsonObject($request));

            return $this->json($this->present($this->index->search('publications.search', $this->publicationQueryBuilder->search($search))));
        } catch (ApiException $exception) {
            return $this->error($exception);
        }
    }

    #[Route('/sections/search', name: 'publication_sections_search', methods: ['POST'])]
    public function sections(Request $request): JsonResponse
    {
        try {
            $search = SectionSearchRequest::fromArray($this->decoder->jsonObject($request));

            return $this->json($this->present($this->index->search('publications.sections.search', $this->sectionQueryBuilder->search($search))));
        } catch (ApiException $exception) {
            return $this->error($exception);
        }
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
