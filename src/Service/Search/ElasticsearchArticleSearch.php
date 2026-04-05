<?php

namespace App\Service\Search;

use App\Dto\SearchFilters;
use App\Entity\Article;
use App\Enum\KindsEnum;
use Elastica\Aggregation\Filters as FiltersAgg;
use Elastica\Index;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;
use Elastica\Query\Range;
use Elastica\Query\Term;
use Elastica\Query\Terms;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Log\LoggerInterface;

class ElasticsearchArticleSearch implements ArticleSearchInterface
{
    public function __construct(
        private readonly FinderInterface $finder,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
        private readonly ?Index $index = null
    ) {
    }

    public function search(string $query, int $limit = 12, int $offset = 0): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $mainQuery = new Query();
            $boolQuery = new BoolQuery();

            // Add phrase match for exact matches (high boost)
            $phraseMatch = new Query\MatchPhrase();
            $phraseMatch->setField('search_combined', [
                'query' => $query,
                'boost' => 10
            ]);
            $boolQuery->addShould($phraseMatch);

            // Main multi-match query with optimized settings
            $multiMatch = new MultiMatch();
            $multiMatch->setQuery($query);
            $multiMatch->setFields(['search_combined']);
            $multiMatch->setFuzziness('AUTO');
            $boolQuery->addMust($multiMatch);

            // Exclude specific patterns
            $boolQuery->addMustNot(new Query\Wildcard('slug', '*/*'));

            $mainQuery->setQuery($boolQuery);

            // Simplified collapse - no inner_hits for better performance
            $mainQuery->setParam('collapse', [
                'field' => 'slug'
            ]);

            // Lower minimum score for better recall
            $mainQuery->setMinScore(0.25);

            // Sort by score first, then date
            $mainQuery->setSort([
                '_score' => ['order' => 'desc'],
                'createdAt' => ['order' => 'desc']
            ]);

            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            // Execute the search
            $results = $this->finder->find($mainQuery);
            $this->logger->info('Elasticsearch search results count: ' . count($results));

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch search error: ' . $e->getMessage());
            return [];
        }
    }

    public function advancedSearch(string $query, SearchFilters $filters, int $limit = 12, int $offset = 0): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $mainQuery = new Query();
            $boolQuery = new BoolQuery();

            // Text query — if provided, use the same multi-match approach as search()
            if (!empty($query)) {
                $phraseMatch = new Query\MatchPhrase();
                $phraseMatch->setField('search_combined', [
                    'query' => $query,
                    'boost' => 10,
                ]);
                $boolQuery->addShould($phraseMatch);

                $multiMatch = new MultiMatch();
                $multiMatch->setQuery($query);
                $multiMatch->setFields(['search_combined']);
                $multiMatch->setFuzziness('AUTO');
                $boolQuery->addMust($multiMatch);
            }

            // Exclude slug patterns containing '/'
            $boolQuery->addMustNot(new Query\Wildcard('slug', '*/*'));

            // ── Filters ──────────────────────────────────────────

            // Date range
            $rangeParams = [];
            if ($filters->dateFrom) {
                $rangeParams['gte'] = $filters->dateFrom;
            }
            if ($filters->dateTo) {
                $rangeParams['lte'] = $filters->dateTo;
            }
            if (!empty($rangeParams)) {
                $boolQuery->addFilter(new Range('createdAt', $rangeParams));
            }

            // Author (hex pubkey)
            if (!empty($filters->author)) {
                $boolQuery->addFilter(new Term(['pubkey' => $filters->author]));
            }

            // Tags
            $tagsArray = $filters->getTagsArray();
            if (!empty($tagsArray)) {
                // All supplied tags must be present (AND)
                foreach ($tagsArray as $tag) {
                    $boolQuery->addFilter(new Term(['topics' => $tag]));
                }
            }

            // Kind filter
            if ($filters->kind !== null) {
                $boolQuery->addFilter(new Term(['kind' => $filters->kind]));
            }

            // If no text query was given, match all (filters only)
            if (empty($query)) {
                $boolQuery->addMust(new Query\MatchAll());
            }

            $mainQuery->setQuery($boolQuery);

            // Collapse on slug
            $mainQuery->setParam('collapse', ['field' => 'slug']);

            // Only set minimum score when there is a text query
            if (!empty($query)) {
                $mainQuery->setMinScore(0.25);
            }

            // Sort
            $sort = match ($filters->sortBy) {
                'newest' => [['createdAt' => ['order' => 'desc']]],
                'oldest' => [['createdAt' => ['order' => 'asc']]],
                default  => !empty($query)
                    ? [['_score' => ['order' => 'desc']], ['createdAt' => ['order' => 'desc']]]
                    : [['createdAt' => ['order' => 'desc']]],
            };
            $mainQuery->setSort($sort);

            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            $results = $this->finder->find($mainQuery);
            $this->logger->info('Elasticsearch advancedSearch results count: ' . count($results));

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch advancedSearch error: ' . $e->getMessage());
            return [];
        }
    }

    public function findBySlugs(array $slugs, int $limit = 200): array
    {
        if (!$this->enabled || empty($slugs)) {
            return [];
        }

        try {
            $termsQuery = new Terms('slug', array_values($slugs));
            $query = new Query($termsQuery);
            $query->setSize($limit);

            return $this->finder->find($query);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findBySlugs error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByTopics(array $topics, int $limit = 12, int $offset = 0): array
    {
        if (!$this->enabled || empty($topics)) {
            return [];
        }

        try {
            $boolQuery = new BoolQuery();
            $termsQuery = new Terms('topics', $topics);
            $boolQuery->addMust($termsQuery);

            // Exclude specific patterns
            $boolQuery->addMustNot(new Query\Wildcard('slug', '*/*'));

            $mainQuery = new Query($boolQuery);
            $mainQuery->setSort([
                'createdAt' => ['order' => 'desc']
            ]);
            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            // Collapse on slug to get unique articles (same as findByTag)
            $mainQuery->setParam('collapse', [
                'field' => 'slug'
            ]);

            return $this->finder->find($mainQuery);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findByTopics error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByPubkey(string $pubkey, int $limit = 12, int $offset = 0): array
    {
        if (!$this->enabled || empty($pubkey)) {
            return [];
        }

        try {
            $boolQuery = new BoolQuery();
            $termQuery = new Query\Term();
            $termQuery->setTerm('pubkey', $pubkey);
            $boolQuery->addMust($termQuery);

            // Exclude specific patterns
            $boolQuery->addMustNot(new Query\Wildcard('slug', '*/*'));

            $mainQuery = new Query($boolQuery);
            $mainQuery->setSort([
                'createdAt' => ['order' => 'desc']
            ]);
            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            return $this->finder->find($mainQuery);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findByPubkey error: ' . $e->getMessage());
            return [];
        }
    }

    public function findLatest(int $limit = 50, array $excludedPubkeys = []): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $boolQuery = new BoolQuery();

            // Exclude drafts (kind 30024)
            $boolQuery->addMustNot(new Term(['kind' => KindsEnum::LONGFORM_DRAFT->value]));

            if (!empty($excludedPubkeys)) {
                $boolQuery->addMustNot(new Terms('pubkey', $excludedPubkeys));
            }

            $mainQuery = new Query($boolQuery);
            $mainQuery->setSize($limit);
            $mainQuery->setSort(['createdAt' => ['order' => 'desc']]);

            // Collapse on slug to get unique articles
            $mainQuery->setParam('collapse', [
                'field' => 'slug'
            ]);

            return $this->finder->find($mainQuery);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findLatest error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByTag(string $tag, int $limit = 20, int $offset = 0): array
    {
        if (!$this->enabled || empty($tag)) {
            return [];
        }

        try {
            $boolQuery = new BoolQuery();
            $boolQuery->addFilter(new Query\Term(['topics' => strtolower(trim($tag))]));

            $mainQuery = new Query($boolQuery);
            $mainQuery->setSort(['createdAt' => ['order' => 'desc']]);
            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            // Collapse on slug to get unique articles
            $mainQuery->setParam('collapse', [
                'field' => 'slug'
            ]);

            return $this->finder->find($mainQuery);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findByTag error: ' . $e->getMessage());
            return [];
        }
    }

    public function getTagCounts(array $tags): array
    {
        if (!$this->enabled || empty($tags) || $this->index === null) {
            return [];
        }

        try {
            $tags = array_values(array_unique(array_map('strtolower', array_map('trim', $tags))));

            $query = new Query(new Query\MatchAll());
            $filters = new FiltersAgg('tag_counts');

            foreach ($tags as $tag) {
                $boolQuery = new BoolQuery();
                $boolQuery->addFilter(new Query\Term(['topics' => $tag]));
                $filters->addFilter($boolQuery, $tag);
            }

            $query->addAggregation($filters);
            $query->setSize(0);

            $result = $this->index->search($query);
            $agg = $result->getAggregation('tag_counts')['buckets'] ?? [];

            $out = [];
            foreach ($tags as $tag) {
                $out[$tag] = isset($agg[$tag]['doc_count']) ? (int) $agg[$tag]['doc_count'] : 0;
            }
            return $out;
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch getTagCounts error: ' . $e->getMessage());
            return [];
        }
    }

    public function isAvailable(): bool
    {
        return $this->enabled;
    }
}

