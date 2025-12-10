<?php

namespace App\Service\Search;

use App\Entity\User;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;
use Elastica\Query\Terms;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Log\LoggerInterface;

class ElasticsearchUserSearch implements UserSearchInterface
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
            $multiMatch->setFields([
                'displayName^3',
                'name^3',
                'nip05^2',
                'about',
                'search_combined'
            ]);
            $multiMatch->setFuzziness('AUTO');
            $boolQuery->addMust($multiMatch);

            $mainQuery->setQuery($boolQuery);

            // Lower minimum score for better recall
            $mainQuery->setMinScore(0.25);

            // Sort by score first
            $mainQuery->setSort([
                '_score' => ['order' => 'desc']
            ]);

            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            // Execute the search
            $results = $this->finder->find($mainQuery);
            $this->logger->info('Elasticsearch user search results count: ' . count($results));

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch user search error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByNpubs(array $npubs, int $limit = 200): array
    {
        if (!$this->enabled || empty($npubs)) {
            return [];
        }

        try {
            $termsQuery = new Terms('npub', array_values($npubs));
            $query = new Query($termsQuery);
            $query->setSize($limit);

            return $this->finder->find($query);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findByNpubs error: ' . $e->getMessage());
            return [];
        }
    }

    public function findByRole(string $role, ?string $query = null, int $limit = 12, int $offset = 0): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $boolQuery = new BoolQuery();

            // Add role filter
            $termQuery = new Query\Term();
            $termQuery->setTerm('roles', $role);
            $boolQuery->addMust($termQuery);

            // Add optional search query
            if ($query !== null && trim($query) !== '') {
                $multiMatch = new MultiMatch();
                $multiMatch->setQuery($query);
                $multiMatch->setFields([
                    'displayName^3',
                    'name^3',
                    'nip05^2',
                    'about',
                    'search_combined'
                ]);
                $multiMatch->setFuzziness('AUTO');
                $boolQuery->addMust($multiMatch);
            }

            $mainQuery = new Query($boolQuery);
            $mainQuery->setSort([
                '_score' => ['order' => 'desc']
            ]);
            $mainQuery->setFrom($offset);
            $mainQuery->setSize($limit);

            return $this->finder->find($mainQuery);
        } catch (\Exception $e) {
            $this->logger->error('Elasticsearch findByRole error: ' . $e->getMessage());
            return [];
        }
    }
}

