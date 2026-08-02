<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Client;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Typed wrapper around the newsroom internal read-only article API.
 *
 * The MCP service never touches Postgres/Elasticsearch directly — it reads all
 * data through this client, authenticating with the shared X-Internal-Token
 * header. Responses are the stable contract produced by the newsroom's
 * InternalArticlePresenter.
 */
class NewsroomApiClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $internalToken,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 12, int $offset = 0): array
    {
        return $this->getList('/internal/api/articles/search', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getArticle(string $coordinate): ?array
    {
        $data = $this->request('GET', '/internal/api/articles/get', ['coordinate' => $coordinate]);

        $result = $data['result'] ?? null;

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latest(int $limit = 20): array
    {
        return $this->getList('/internal/api/articles/latest', ['limit' => $limit]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function byAuthor(string $author, int $limit = 12, int $offset = 0): array
    {
        return $this->getList('/internal/api/articles/by-author', [
            'author' => $author,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @param list<string> $topics
     * @return array<int, array<string, mixed>>
     */
    public function byTopic(array $topics, int $limit = 12, int $offset = 0): array
    {
        return $this->getList('/internal/api/articles/by-topic', [
            'topics' => implode(',', $topics),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @param list<string> $topics
     * @return array<string, int>
     */
    public function topicCounts(array $topics): array
    {
        $data = $this->request('GET', '/internal/api/articles/topics', [
            'topics' => implode(',', $topics),
        ]);

        $counts = $data['counts'] ?? [];

        return is_array($counts) ? $counts : [];
    }

    /**
     * @param array<string, scalar> $query
     * @return array<int, array<string, mixed>>
     */
    private function getList(string $path, array $query): array
    {
        $data = $this->request('GET', $path, $query);

        $results = $data['results'] ?? [];

        return is_array($results) ? $results : [];
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $response = $this->http->request($method, rtrim($this->baseUrl, '/') . $path, [
            'query' => $query,
            'headers' => [
                'X-Internal-Token' => $this->internalToken,
                'Accept' => 'application/json',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status === 404) {
            return [];
        }

        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Newsroom internal API returned HTTP %d for %s', $status, $path));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->toArray(false);

        return $decoded;
    }
}
