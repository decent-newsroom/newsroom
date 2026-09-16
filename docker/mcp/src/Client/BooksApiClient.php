<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Typed read-only client for the newsroom Books API.
 */
class BooksApiClient
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
        return $this->request('GET', '/books/api/events/' . rawurlencode($eventId), null);
    }

    /**
     * @param array<string, scalar> $payload
     * @return array<int, array<string, mixed>>
     */
    private function postList(string $path, array $payload): array
    {
        $result = $this->request('POST', $path, $payload);

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, scalar>|null $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, ?array $payload): ?array
    {
        $startedAt = microtime(true);
        $context = [
            'service' => 'books',
            'method' => $method,
            'path' => $path,
        ];
        if ($payload !== null) {
            $context['payload'] = $payload;
        }
        $this->logger->info('MCP upstream request started', $context);

        $status = null;

        try {
            $options = [
                'headers' => ['Accept' => 'application/json'],
            ];
            if ($payload !== null) {
                $options['json'] = $payload;
            }

            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . $path, $options);
            $status = $response->getStatusCode();
            if ($status === 404) {
                $this->logger->info('MCP upstream request completed', $context + [
                    'status' => $status,
                    'result' => 'not_found',
                    'duration_ms' => $this->durationMs($startedAt),
                ]);

                return null;
            }

            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Books API returned HTTP %d for %s', $status, $path));
            }

            /** @var mixed $decoded */
            $decoded = $response->toArray(false);
            $this->logger->info('MCP upstream request completed', $context + [
                'status' => $status,
                'duration_ms' => $this->durationMs($startedAt),
            ]);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            $this->logger->error('MCP upstream request failed', $context + [
                'status' => $status,
                'duration_ms' => $this->durationMs($startedAt),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function durationMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 1);
    }
}
