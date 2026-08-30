<?php

declare(strict_types=1);

namespace App\Bookshelf;

use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClient;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves books through Mercury and this instance's Elasticsearch-backed Books API.
 */
final class BookshelfBookLoader
{
    public function __construct(
        private readonly MercuryBookService $mercuryBookService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $localBooksApiBaseUrl,
    ) {
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @return array<int, array<string, mixed>>
     */
    public function getBooksForReferences(array $references): array
    {
        if ($references === []) {
            return [];
        }

        $localService = new MercuryBookService(new MercuryApiClient(
            new BooksApiMercuryHttpClient($this->httpClient),
            $this->localBooksApiBaseUrl,
        ));

        $results = [];
        $firstException = null;
        foreach ([$this->mercuryBookService, $localService] as $service) {
            try {
                $results[] = $service->getBooksForReferences($references);
            } catch (MercuryApiException $exception) {
                $firstException ??= $exception;
            }
        }

        if ($results === []) {
            throw $firstException ?? new MercuryApiException('No book resolver was available.');
        }

        $books = $this->mergeBooksForReferences($references, $results);
        if ($books === [] && $firstException !== null) {
            throw $firstException;
        }

        return $books;
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @param array<int, array<int, array<string, mixed>>> $results
     * @return array<int, array<string, mixed>>
     */
    private function mergeBooksForReferences(array $references, array $results): array
    {
        $booksById = [];
        $booksByCoordinate = [];
        foreach ($results as $books) {
            foreach ($books as $book) {
                $id = $book['id'] ?? null;
                if (is_string($id) && $id !== '') {
                    $booksById[$id] = $this->newest($booksById[$id] ?? null, $book);
                }

                $coordinate = $book['coordinate'] ?? null;
                if (is_string($coordinate) && $coordinate !== '') {
                    $booksByCoordinate[$coordinate] = $this->newest($booksByCoordinate[$coordinate] ?? null, $book);
                }
            }
        }

        $books = [];
        $seen = [];
        foreach ($references as $reference) {
            $book = $reference['eventId'] !== null
                ? ($booksById[$reference['eventId']] ?? null)
                : null;
            if ($book === null && $reference['coordinate'] !== null) {
                $book = $booksByCoordinate[$reference['coordinate']] ?? null;
            }
            if ($book === null) {
                continue;
            }

            $deduplicationKey = is_string($book['coordinate'] ?? null) && $book['coordinate'] !== ''
                ? 'coordinate:' . $book['coordinate']
                : 'id:' . ($book['id'] ?? '');
            if (isset($seen[$deduplicationKey])) {
                continue;
            }

            $seen[$deduplicationKey] = true;
            $books[] = $book;
        }

        return $books;
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function newest(?array $current, array $candidate): array
    {
        if ($current === null || (int) ($candidate['createdAt'] ?? 0) > (int) ($current['createdAt'] ?? 0)) {
            return $candidate;
        }

        return $current;
    }
}
