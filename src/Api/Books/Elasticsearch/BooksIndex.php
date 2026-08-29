<?php

declare(strict_types=1);

namespace App\Api\Books\Elasticsearch;

use App\Api\Books\Http\ApiException;
use Elastica\Client;
use Psr\Log\LoggerInterface;

final class BooksIndex
{
    /** @var list<string> */
    public const SOURCE_FIELDS = ['content', 'created_at', 'id', 'kind', 'pubkey', 'sig', 'tags'];

    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger,
        private readonly string $booksElasticsearchIndex,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function search(string $operation, array $query): array
    {
        $startedAt = microtime(true);
        $queryContext = $this->queryContext($operation, $query);
        $this->logger->info('Books API Elasticsearch query started', $queryContext);

        try {
            $resultSet = $this->client->getIndex($this->booksElasticsearchIndex)->search($query);
            $results = [];
            foreach ($resultSet->getResults() as $result) {
                $results[] = [
                    'id' => (string) $result->getId(),
                    'source' => $result->getData(),
                ];
            }
            $this->logger->info('Books API Elasticsearch query completed', $queryContext + [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'result_count' => count($results),
            ]);

            return $results;
        } catch (\Throwable $exception) {
            $this->logger->error('Books API Elasticsearch query failed', $queryContext + [
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'error_class' => $exception::class,
            ]);
            throw new ApiException(503, ['Books search is temporarily unavailable'], 'Service unavailable');
        }
    }

    public function isAvailable(): bool
    {
        try {
            return $this->client->getIndex($this->booksElasticsearchIndex)->exists();
        } catch (\Throwable $exception) {
            $this->logger->warning('Books API Elasticsearch availability check failed', [
                'error_class' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * Record only request shape, never user-entered query values, event IDs,
     * public keys, or Elasticsearch connection details.
     *
     * @param array<string, mixed> $query
     * @return array<string, bool|int|string|null>
     */
    private function queryContext(string $operation, array $query): array
    {
        $bool = $query['query']['bool'] ?? [];
        $filterCount = is_array($bool) && isset($bool['filter']) && is_array($bool['filter']) ? count($bool['filter']) : 0;
        $shouldCount = is_array($bool) && isset($bool['should']) && is_array($bool['should']) ? count($bool['should']) : 0;
        $sort = isset($query['sort']) && is_array($query['sort']) ? $query['sort'] : [];

        return [
            'operation' => $operation,
            'index_alias' => $this->booksElasticsearchIndex,
            'requested_limit' => isset($query['size']) && is_int($query['size']) ? $query['size'] : null,
            'filter_count' => $filterCount,
            'should_clause_count' => $shouldCount,
            'has_scored_sort' => in_array('_score', array_map(
                static fn (mixed $sortClause): string => is_array($sortClause) ? (string) array_key_first($sortClause) : '',
                $sort,
            ), true),
        ];
    }
}
