<?php

declare(strict_types=1);

namespace App\Service\Mercury;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MercuryApiClient
{
    private const FILTER_BATCH_SIZE = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $mercuryApiBaseUrl,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPublications(string $query, int $limit = 60): array
    {
        return $this->requestEventList('POST', '/api/publications/search', [
            'json' => [
                'q' => $query,
                'limit' => max(1, min($limit, 100)),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEvent(string $eventId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $this->url('/api/events/' . $eventId), [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
                'max_duration' => 20,
            ]);
            $status = $response->getStatusCode();

            if ($status === 404) {
                return null;
            }

            if ($status < 200 || $status >= 300) {
                throw new MercuryApiException(sprintf('Mercury returned HTTP %d.', $status));
            }

            $payload = $response->toArray(false);
        } catch (MercuryApiException $exception) {
            throw $exception;
        } catch (ExceptionInterface $exception) {
            throw new MercuryApiException('Mercury could not be reached.', previous: $exception);
        }

        $event = $payload['data'] ?? null;
        if (!is_array($event)) {
            throw new MercuryApiException('Mercury returned an invalid event response.');
        }

        return $event;
    }

    /**
     * Fetches chapter events in bounded batches. Mercury returns each batch by
     * created_at, so callers must restore the publication index order.
     *
     * @param string[] $eventIds
     * @return array<int, array<string, mixed>>
     */
    public function getEventsByIds(array $eventIds): array
    {
        return $this->getEventsByIdsAndKind($eventIds, 30041);
    }

    /**
     * @param string[] $eventIds
     * @return array<int, array<string, mixed>>
     */
    public function getPublicationEventsByIds(array $eventIds): array
    {
        return $this->getEventsByIdsAndKind($eventIds, 30040);
    }

    /**
     * @param string[] $authors
     * @return array<int, array<string, mixed>>
     */
    public function getPublicationsByAuthors(array $authors, int $limit): array
    {
        $authors = array_values(array_unique(array_filter(
            $authors,
            static fn (string $author): bool => preg_match('/^[a-f0-9]{64}$/i', $author) === 1,
        )));

        if ($authors === []) {
            return [];
        }

        return $this->filterEvents([
            'authors' => $authors,
            'kinds' => [30040],
            'limit' => max(1, min($limit, 500)),
        ]);
    }

    public function getRelayHint(): ?string
    {
        $parts = parse_url($this->mercuryApiBaseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = match (strtolower((string) $parts['scheme'])) {
            'https' => 'wss',
            'http' => 'ws',
            'wss', 'ws' => strtolower((string) $parts['scheme']),
            default => null,
        };
        if ($scheme === null) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return sprintf('%s://%s%s', $scheme, $parts['host'], $port);
    }

    /**
     * @param string[] $eventIds
     * @return array<int, array<string, mixed>>
     */
    private function getEventsByIdsAndKind(array $eventIds, int $kind): array
    {
        $eventIds = array_values(array_unique(array_filter(
            $eventIds,
            static fn (string $id): bool => preg_match('/^[a-f0-9]{64}$/i', $id) === 1,
        )));

        $events = [];
        foreach (array_chunk($eventIds, self::FILTER_BATCH_SIZE) as $batch) {
            $events = [
                ...$events,
                ...$this->filterEvents([
                    'ids' => $batch,
                    'kinds' => [$kind],
                    'limit' => count($batch),
                ]),
            ];
        }

        return $events;
    }

    /**
     * @param string[] $authors
     * @return array<int, array<string, mixed>>
     */
    public function getChaptersByAuthors(array $authors, int $limit): array
    {
        $authors = array_values(array_unique(array_filter(
            $authors,
            static fn (string $author): bool => $author !== '',
        )));

        if ($authors === []) {
            return [];
        }

        return $this->filterEvents([
            'authors' => $authors,
            'kinds' => [30041],
            'limit' => max(1, min($limit, 500)),
        ]);
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<int, array<string, mixed>>
     */
    private function filterEvents(array $filter): array
    {
        return $this->requestEventList('POST', '/api/events/filter', [
            'json' => $filter,
            'timeout' => 20,
            'max_duration' => 45,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    private function requestEventList(string $method, string $path, array $options): array
    {
        $options['headers'] = [
            ...($options['headers'] ?? []),
            'Accept' => 'application/json',
        ];
        $options['timeout'] ??= 10;
        $options['max_duration'] ??= 20;

        try {
            $response = $this->httpClient->request($method, $this->url($path), $options);
            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                throw new MercuryApiException(sprintf('Mercury returned HTTP %d.', $status));
            }

            $payload = $response->toArray(false);
        } catch (MercuryApiException $exception) {
            throw $exception;
        } catch (ExceptionInterface $exception) {
            throw new MercuryApiException('Mercury could not be reached.', previous: $exception);
        }

        $events = $payload['data'] ?? null;
        if (!is_array($events) || !array_is_list($events)) {
            throw new MercuryApiException('Mercury returned an invalid event list.');
        }

        return array_values(array_filter($events, 'is_array'));
    }

    private function url(string $path): string
    {
        return rtrim($this->mercuryApiBaseUrl, '/') . $path;
    }
}
