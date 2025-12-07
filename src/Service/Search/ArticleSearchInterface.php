<?php

namespace App\Service\Search;

use App\Entity\Article;

interface ArticleSearchInterface
{
    /**
     * Search for articles matching the given query
     *
     * @param string $query The search query
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return Article[]
     */
    public function search(string $query, int $limit = 12, int $offset = 0): array;

    /**
     * Find articles by slugs
     *
     * @param array $slugs Array of article slugs
     * @param int $limit Maximum number of results
     * @return Article[]
     */
    public function findBySlugs(array $slugs, int $limit = 200): array;

    /**
     * Find articles by topics
     *
     * @param array $topics Array of topics
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return Article[]
     */
    public function findByTopics(array $topics, int $limit = 12, int $offset = 0): array;

    /**
     * Find articles by pubkey (author)
     *
     * @param string $pubkey Author's public key
     * @param int $limit Maximum number of results
     * @param int $offset Offset for pagination
     * @return Article[]
     */
    public function findByPubkey(string $pubkey, int $limit = 12, int $offset = 0): array;

    /**
     * Check if the search service is available
     *
     * @return bool
     */
    public function isAvailable(): bool;
}

