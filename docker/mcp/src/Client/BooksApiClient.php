<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Typed read-only client for the newsroom Books API.
 */
class BooksApiClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param array<string, scalar> $criteria
     * @return array<int, array<string, mixed>>
     */
    public function searchPublications(array $criteria): array
    {
        return $this->postList('/books/api/publications/search', $criteria);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchSections(string $query, int $limit = 25): array
    {
        return $this->postList('/books/api/publications/sections/search', [
            'q' => $query,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBook(string $eventId): ?array
    {
        $response = $this->http->request(
            'GET',
            rtrim($this->baseUrl, '/') . '/books/api/events/' . rawurlencode($eventId),
            ['headers' => ['Accept' => 'application/json']],
        );

        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Books API returned HTTP %d for event lookup', $status));
        }

        /** @var array<string, mixed> $event */
        $event = $response->toArray(false);

        return $event;
    }

    /**
     * @param array<string, scalar> $payload
     * @return array<int, array<string, mixed>>
     */
    private function postList(string $path, array $payload): array
    {
        $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . $path, [
            'json' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Books API returned HTTP %d for %s', $status, $path));
        }

        /** @var mixed $decoded */
        $decoded = $response->toArray(false);

        return is_array($decoded) ? $decoded : [];
    }
}
