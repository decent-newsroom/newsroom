<?php

namespace App\Twig\Components;

use App\Credits\Service\CreditsManager;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\Contracts\Cache\CacheInterface;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\MultiMatch;

#[AsLiveComponent]
final class SearchComponent
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp(writable: true, useSerializerForHydration: true)]
    public string $query = '';
    public array $results = [];

    public bool $interactive = true;
    public string $currentRoute;

    public int $credits = 0;
    public ?string $npub = null;

    #[LiveProp]
    public int $vol = 0;

    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveProp]
    public int $resultsPerPage = 12;

    // New: render results with add-to-list buttons when true
    #[LiveProp(writable: true)]
    public bool $selectMode = false;

    private const string SESSION_KEY = 'last_search_results';
    private const string SESSION_QUERY_KEY = 'last_search_query';

    public function __construct(
        private readonly FinderInterface $finder,
        private readonly CreditsManager $creditsManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly RequestStack $requestStack
    )
    {
    }

    public function mount($currentRoute = 'search'): void
    {
        $this->currentRoute = $currentRoute;
        $token = $this->tokenStorage->getToken();
        $this->npub = $token?->getUserIdentifier();

        if ($this->npub !== null) {
            try {
                $this->credits = $this->creditsManager->getBalance($this->npub);
                $this->logger->info($this->credits);
            } catch (InvalidArgumentException $e) {
                $this->logger->error($e);
                $this->credits = $this->creditsManager->resetBalance($this->npub);
            }
        }

        // Restore search results from session if available and no query provided
        if (empty($this->query) && $this->currentRoute == 'search') {
            $session = $this->requestStack->getSession();
            if ($session->has(self::SESSION_QUERY_KEY)) {
                $this->query = $session->get(self::SESSION_QUERY_KEY);
                $this->results = $session->get(self::SESSION_KEY, []);
                $this->logger->info('Restored search results from session for query: ' . $this->query);
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    #[LiveAction]
    public function search(): void
    {
        $token = $this->tokenStorage->getToken();
        $this->npub = $token?->getUserIdentifier();

        $this->logger->info("Query: {$this->query}, npub: {$this->npub}");

        if (empty($this->query)) {
            $this->results = [];
            $this->clearSearchCache();
            return;
        }

        try {
            $this->credits = $this->creditsManager->getBalance($this->npub);
        } catch (InvalidArgumentException $e) {
            $this->credits = $this->creditsManager->resetBalance($this->npub);
        }

        // Check if the same query exists in session
        $session = $this->requestStack->getSession();
        if ($session->has(self::SESSION_QUERY_KEY) &&
            $session->get(self::SESSION_QUERY_KEY) === $this->query) {
            $this->results = $session->get(self::SESSION_KEY, []);
            $this->logger->info('Using cached search results for query: ' . $this->query);
            return;
        }

        if (!$this->creditsManager->canAfford($this->npub, 1)) {
            $this->results = [];
            return;
        }

        try {
            $this->results = [];
            $this->creditsManager->spendCredits($this->npub, 1, 'search');
            $this->credits--;

            // Step 1: Run a quick naive query on title and summary only
            $quickResults = $this->performQuickSearch($this->query);

            // Step 2: Run the comprehensive query
            $comprehensiveResults = $this->performComprehensiveSearch($this->query);

            // Combine results, making sure we don't have duplicates
            $this->results = $this->mergeSearchResults($quickResults, $comprehensiveResults);

            // Cache the search results in session
            $this->saveSearchToSession($this->query, $this->results);

        } catch (\Exception $e) {
            $this->logger->error('Search error: ' . $e->getMessage());
            $this->results = [];
        }
    }

    #[LiveAction]
    public function addToReadingList(?string $coordinate = null): void
    {
        if ($coordinate === null || $coordinate === '') {
            return; // nothing to add
        }
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');
        if (!$draft instanceof \App\Dto\CategoryDraft) {
            $draft = new \App\Dto\CategoryDraft();
            $draft->title = $draft->title ?: 'Reading List';
            if (!$draft->slug) {
                $draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
            }
        }
        if (!in_array($coordinate, $draft->articles, true)) {
            $draft->articles[] = $coordinate;
        }
        $session->set('read_wizard', $draft);
        $this->emit('readingListUpdated');
    }

    /**
     * Perform a quick search on title and summary only
     */
    private function performQuickSearch(string $query): array
    {
        $mainQuery = new Query();

        // Simple multi-match query for searching across title and summary only
        $multiMatch = new MultiMatch();
        $multiMatch->setQuery($query);
        $multiMatch->setFields([
            'title^5',  // Increased weight for title
            'summary^3' // Increased weight for summary
        ]);
        $multiMatch->setType(MultiMatch::TYPE_BEST_FIELDS); // Changed to BEST_FIELDS for more precise matching
        $multiMatch->setOperator(MultiMatch::OPERATOR_AND); // Require all terms to match for better precision

        $boolQuery = new BoolQuery();
        $boolQuery->addMust($multiMatch);
        $boolQuery->addMustNot(new Query\Wildcard('slug', '*/*'));
        $mainQuery->setQuery($boolQuery);

        // Use the collapse field to prevent duplicate content
        $mainQuery->setParam('collapse', [
            'field' => 'slug'
        ]);

        // Set a minimum score to filter out irrelevant results
        $mainQuery->setMinScore(0.5); // Higher minimum score for quick results

        // Sort by relevance only for quick results
        $mainQuery->setSort(['_score' => ['order' => 'desc']]);

        // Limit to 5 results for the quick search
        $mainQuery->setSize(5);

        // Execute the quick search
        $results = $this->finder->find($mainQuery);
        $this->logger->info('Quick search results count: ' . count($results));

        return $results;
    }

    /**
     * Perform a comprehensive search across all fields
     */
    private function performComprehensiveSearch(string $query): array
    {
        $mainQuery = new Query();

        // Build bool query with multiple conditions for more precise matching
        $boolQuery = new BoolQuery();

        // Add exact phrase match with high boost for very relevant results
        $phraseMatch = new Query\MatchPhrase();
        $phraseMatch->setField('title', [
            'query' => $query,
            'boost' => 10
        ]);
        $boolQuery->addShould($phraseMatch);

        // Add regular multi-match with adjusted weights
        $multiMatch = new MultiMatch();
        $multiMatch->setQuery($query);
        $multiMatch->setFields([
            'title^4',
            'summary^3',
            'content^1.2',
            'topics^2'
        ]);
        $multiMatch->setType(MultiMatch::TYPE_MOST_FIELDS);
        $multiMatch->setFuzziness('AUTO');
        $multiMatch->setOperator(MultiMatch::OPERATOR_AND); // Require all terms to match
        $boolQuery->addMust($multiMatch);

        // Exclude specific patterns
        $boolQuery->addMustNot(new Query\Wildcard('slug', '*/*'));

        // For content relevance, filter by minimum content length
        $lengthFilter = new Query\QueryString();
        $lengthFilter->setQuery('content:/.{250,}/');
        $boolQuery->addFilter($lengthFilter);

        $mainQuery->setQuery($boolQuery);

        // Use the collapse field
        $mainQuery->setParam('collapse', [
            'field' => 'slug',
            'inner_hits' => [
                'name' => 'latest_articles',
                'size' => 1
            ]
        ]);

        // Increase minimum score to filter out irrelevant results
        $mainQuery->setMinScore(0.35);

        // Sort by score and createdAt
        $mainQuery->setSort([
            '_score' => ['order' => 'desc'],
            'createdAt' => ['order' => 'desc']
        ]);

        // Add pagination for the comprehensive results
        // Adjust the pagination to account for the quick results
        $offset = ($this->page - 1) * ($this->resultsPerPage - 5);
        if ($offset < 0) $offset = 0;

        $mainQuery->setFrom($offset);
        $mainQuery->setSize($this->resultsPerPage - 5);

        // Execute the search
        $results = $this->finder->find($mainQuery);
        $this->logger->info('Comprehensive search results count: ' . count($results));

        return $results;
    }

    /**
     * Merge quick and comprehensive search results, ensuring no duplicates
     */
    private function mergeSearchResults(array $quickResults, array $comprehensiveResults): array
    {
        $mergedResults = $quickResults;
        $slugs = [];

        // Collect slugs from quick results to avoid duplicates
        foreach ($quickResults as $result) {
            $slugs[] = $result->getSlug();
        }

        // Add comprehensive results that aren't already in quick results
        foreach ($comprehensiveResults as $result) {
            if (!in_array($result->getSlug(), $slugs)) {
                $mergedResults[] = $result;
                $slugs[] = $result->getSlug();
            }
        }

        return $mergedResults;
    }

    #[LiveListener('creditsAdded')]
    public function incrementCreditsCount(): void
    {
        $this->credits += 5;
    }

    /**
     * Save search results to session
     */
    private function saveSearchToSession(string $query, array $results): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_QUERY_KEY, $query);
        $session->set(self::SESSION_KEY, $results);
        $this->logger->info('Saved search results to session for query: ' . $query);
    }

    /**
     * Clear search cache from session
     */
    private function clearSearchCache(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_QUERY_KEY);
        $session->remove(self::SESSION_KEY);
        $this->logger->info('Cleared search cache from session');
    }
}
