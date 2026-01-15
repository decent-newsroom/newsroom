<?php

namespace App\Service\Search;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use Psr\Log\LoggerInterface;

class DatabaseArticleSearch implements ArticleSearchInterface
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function search(string $query, int $limit = 12, int $offset = 0): array
    {
        try {
            $results = $this->articleRepository->searchByQuery($query, $limit, $offset);
            $this->logger->info('Database search results count: ' . count($results));
            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Database search error: ' . $e->getMessage());
            return [];
        }
    }

    public function findBySlugs(array $slugs, int $limit = 200): array
    {
        if (empty($slugs)) {
            return [];
        }

        try {
            return $this->articleRepository->findBySlugs($slugs, $limit);
        } catch (\Exception $e) {
            $this->logger->error('Database findBySlugs error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByTopics(array $topics, int $limit = 12, int $offset = 0): array
    {
        if (empty($topics)) {
            return [];
        }

        try {
            return $this->articleRepository->findByTopics($topics, $limit, $offset);
        } catch (\Exception $e) {
            $this->logger->error('Database findByTopics error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByPubkey(string $pubkey, int $limit = 12, int $offset = 0): array
    {
        if (empty($pubkey)) {
            return [];
        }

        try {
            return $this->articleRepository->findByPubkey($pubkey, $limit, $offset);
        } catch (\Exception $e) {
            $this->logger->error('Database findByPubkey error: ' . $e->getMessage());
            return [];
        }
    }

    public function findLatest(int $limit = 50, array $excludedPubkeys = []): array
    {
        try {
            return $this->articleRepository->findLatestArticles($limit, $excludedPubkeys);
        } catch (\Exception $e) {
            $this->logger->error('Database findLatest error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByTag(string $tag, int $limit = 20, int $offset = 0): array
    {
        if (empty($tag)) {
            return [];
        }

        try {
            return $this->articleRepository->findByTopics([strtolower(trim($tag))], $limit, $offset);
        } catch (\Exception $e) {
            $this->logger->error('Database findByTag error: ' . $e->getMessage());
            return [];
        }
    }

    public function getTagCounts(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        try {
            return $this->articleRepository->getTagCounts($tags);
        } catch (\Exception $e) {
            $this->logger->error('Database getTagCounts error: ' . $e->getMessage());
            return [];
        }
    }

    public function isAvailable(): bool
    {
        return true; // Database is always available
    }
}

