<?php

namespace App\Service\Search;

use App\Entity\Article;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;
use Elastica\Query\Terms;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Log\LoggerInterface;

class ElasticsearchArticleSearch implements ArticleSearchInterface
{
    public function __construct(
        private readonly FinderInterface $finder,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true
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

    public function isAvailable(): bool
    {
        return $this->enabled;
    }
}

