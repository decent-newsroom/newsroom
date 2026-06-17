<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Article;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;

/**
 * High-level Content Search API
 *
 * Wraps the lower-level ArticleSearchInterface to provide a formal,
 * feature-rich API for site-wide content discovery:
 * - Topic/tag-based searches
 * - Related article suggestions
 * - Content search with filters
 * - Topic metadata and analytics
 *
 * This service abstracts away the details of whether Elasticsearch or
 * the database is being used, allowing transparent backend switching
 * without affecting callers.
 */
class ContentSearchService
{
    public function __construct(
        private readonly ArticleSearchInterface $articleSearch,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Search articles by one or more topics (tags)
     *
     * Searches for articles matching ANY of the provided topics (OR operation).
     * Results are deduplicated by pubkey + slug and sorted by recency.
     *
     * @param string[] $topics Array of topic/tag names to search
     * @param int $limit Maximum results to return
     * @param int $offset Pagination offset
     * @return Article[]
     *
     * @example
     *   $articles = $this->contentSearch->searchByTopics(
     *       ['bitcoin', 'lightning'],
     *       limit: 20,
     *       offset: 0
     *   );
     */
    public function searchByTopics(array $topics, int $limit = 20, int $offset = 0): array
    {
        if (empty($topics)) {
            return [];
        }

        try {
            $normalizedTopics = array_map(
                fn($t) => strtolower(trim((string) $t)),
                $topics
            );
            $normalizedTopics = array_values(array_filter(array_unique($normalizedTopics)));

            if (empty($normalizedTopics)) {
                return [];
            }

            return $this->articleSearch->findByTopics($normalizedTopics, $limit, $offset);
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::searchByTopics failed', [
                'topics' => $topics,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Find articles related to a given article
     *
     * Returns articles that share tags with the given article, excluding
     * the article itself. Useful for "related articles" sections on article pages.
     *
     * @param Article $article The reference article
     * @param int $limit Maximum results to return
     * @return Article[]
     *
     * @example
     *   $related = $this->contentSearch->findRelatedArticles($currentArticle, limit: 6);
     */
    public function findRelatedArticles(Article $article, int $limit = 6): array
    {
        try {
            $tags = $article->getTopics() ?? [];
            if (empty($tags)) {
                return [];
            }

            $articles = $this->searchByTopics($tags, $limit * 2, 0);

            // Filter out the current article itself (by pubkey + slug)
            $currentKey = $article->getPubkey() . '|' . $article->getSlug();
            return array_filter(
                $articles,
                function (Article $a) use ($currentKey) {
                    $key = $a->getPubkey() . '|' . $a->getSlug();
                    return $key !== $currentKey;
                }
            );
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::findRelatedArticles failed', [
                'article' => $article->getId(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get analytics metadata for one or more topics
     *
     * Returns article counts for each topic, useful for:
     * - Displaying topic popularity in indexes
     * - Building topic-based navigation
     * - Rendering topic statistics in UI
     *
     * @param string[] $topics Array of topic/tag names
     * @return array<string, int> Topic name => article count
     *
     * @example
     *   $counts = $this->contentSearch->getTopicsMetadata(
     *       ['bitcoin', 'nostr', 'lightning']
     *   );
     *   // Returns: ['bitcoin' => 142, 'nostr' => 89, 'lightning' => 56]
     */
    public function getTopicsMetadata(array $topics): array
    {
        if (empty($topics)) {
            return [];
        }

        try {
            $normalizedTopics = array_map(
                fn($t) => strtolower(trim((string) $t)),
                $topics
            );
            $normalizedTopics = array_values(array_filter(array_unique($normalizedTopics)));

            if (empty($normalizedTopics)) {
                return [];
            }

            return $this->articleSearch->getTagCounts($normalizedTopics);
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::getTopicsMetadata failed', [
                'topics' => $topics,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Search content by free-text query
     *
     * Full-text search across article titles, summaries, and content.
     * Supports phrase matching and fuzzy matching when Elasticsearch is enabled.
     *
     * @param string $query Search query/keywords
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return Article[]
     *
     * @example
     *   $results = $this->contentSearch->search('lightning network', limit: 12);
     */
    public function search(string $query, int $limit = 12, int $offset = 0): array
    {
        if (empty(trim($query))) {
            return [];
        }

        try {
            return $this->articleSearch->search(trim($query), $limit, $offset);
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Search by author (pubkey)
     *
     * Returns all published articles by a given author.
     *
     * @param string $pubkeyHex Author's hex public key
     * @param int $limit Maximum results
     * @param int $offset Pagination offset
     * @return Article[]
     *
     * @example
     *   $articles = $this->contentSearch->searchByAuthor($userPubkeyHex, limit: 20);
     */
    public function searchByAuthor(string $pubkeyHex, int $limit = 20, int $offset = 0): array
    {
        if (empty(trim($pubkeyHex))) {
            return [];
        }

        try {
            return $this->articleSearch->findByPubkey(trim($pubkeyHex), $limit, $offset);
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::searchByAuthor failed', [
                'pubkey' => $pubkeyHex,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get latest articles
     *
     * Returns the newest published articles, optionally excluding certain authors.
     * Useful for homepage feeds and trending sections.
     *
     * @param int $limit Maximum results
     * @param string[] $excludedPubkeys Author pubkeys to exclude from results
     * @return Article[]
     *
     * @example
     *   $latest = $this->contentSearch->getLatest(limit: 20);
     *   $filtered = $this->contentSearch->getLatest(
     *       limit: 20,
     *       excludedPubkeys: ['pubkey1', 'pubkey2']
     *   );
     */
    public function getLatest(int $limit = 50, array $excludedPubkeys = []): array
    {
        try {
            $normalized = array_map(
                fn($k) => strtolower(trim((string) $k)),
                $excludedPubkeys
            );
            $normalized = array_values(array_filter(array_unique($normalized)));

            return $this->articleSearch->findLatest($limit, $normalized);
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::getLatest failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Check if the search backend is available
     *
     * Returns false if Elasticsearch is configured but unavailable.
     * The database backend is always available.
     *
     * @return bool
     */
    public function isSearchAvailable(): bool
    {
        try {
            return $this->articleSearch->isAvailable();
        } catch (\Throwable $e) {
            $this->logger->warning('ContentSearchService::isSearchAvailable check failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build topic taxonomies with metadata
     *
     * Enriches a taxonomy structure with article counts for each topic.
     * Useful for rendering topic indexes, menus, and category navigations.
     *
     * @param array<string, array> $taxonomy Taxonomy structure:
     *        [
     *            'category-key' => [
     *                'name' => 'Category Name',
     *                'subcategories' => [
     *                    'subcategory-key' => [
     *                        'name' => 'Subcategory Name',
     *                        'tags' => ['tag1', 'tag2', ...],
     *                        'description' => 'Optional description'
     *                    ],
     *                    ...
     *                ]
     *            ],
     *            ...
     *        ]
     * @return array<string, array> Taxonomy with 'count' field added to each subcategory
     *
     * @example
     *   $enriched = $this->contentSearch->buildTaxonomyWithCounts($myTopics);
     *   // Each subcategory now has ['count' => N, 'name' => ..., 'tags' => ...]
     */
    public function buildTaxonomyWithCounts(array $taxonomy): array
    {
        try {
            // Collate all unique tags from the taxonomy
            $allTags = [];
            foreach ($taxonomy as $cat) {
                foreach ($cat['subcategories'] ?? [] as $sub) {
                    foreach ($sub['tags'] ?? [] as $tag) {
                        $allTags[strtolower($tag)] = true;
                    }
                }
            }

            if (empty($allTags)) {
                return $this->injectEmptyCountsIntoTaxonomy($taxonomy);
            }

            // Fetch counts for all tags at once
            $counts = $this->getTopicsMetadata(array_keys($allTags));

            // Rebuild taxonomy with counts
            $result = [];
            foreach ($taxonomy as $catKey => $cat) {
                $subs = [];
                foreach ($cat['subcategories'] ?? [] as $subKey => $sub) {
                    $sum = 0;
                    foreach ($sub['tags'] ?? [] as $tag) {
                        $sum += $counts[strtolower($tag)] ?? 0;
                    }
                    $subs[$subKey] = $sub + ['count' => $sum];
                }
                $result[$catKey] = $cat;
                $result[$catKey]['subcategories'] = $subs;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('ContentSearchService::buildTaxonomyWithCounts failed', [
                'error' => $e->getMessage(),
            ]);
            return $this->injectEmptyCountsIntoTaxonomy($taxonomy);
        }
    }

    /**
     * Deduplicate articles by pubkey + slug
     *
     * Removes duplicate articles that have the same author and slug.
     * Preserves the first occurrence of each unique pubkey+slug pair.
     *
     * @param Article[] $articles
     * @return Article[]
     */
    public function deduplicateArticles(array $articles): array
    {
        $seen = [];
        $unique = [];

        foreach ($articles as $article) {
            $key = ($article->getPubkey() ?? '') . '|' . ($article->getSlug() ?? '');

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $article;
            }
        }

        return $unique;
    }

    // ──── Private helpers ────

    private function injectEmptyCountsIntoTaxonomy(array $taxonomy): array
    {
        $result = [];
        foreach ($taxonomy as $catKey => $cat) {
            $subs = [];
            foreach ($cat['subcategories'] ?? [] as $subKey => $sub) {
                $subs[$subKey] = $sub + ['count' => 0];
            }
            $result[$catKey] = $cat;
            $result[$catKey]['subcategories'] = $subs;
        }
        return $result;
    }
}

