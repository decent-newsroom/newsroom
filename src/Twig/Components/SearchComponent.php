<?php

namespace App\Twig\Components;

use App\Credits\Service\CreditsManager;
use App\Service\RedisCacheService;
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
    public array $authors = [];

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

    private const SESSION_KEY = 'last_search_results';
    private const SESSION_QUERY_KEY = 'last_search_query';

    public function __construct(
        private readonly FinderInterface $finder,
        private readonly CreditsManager $creditsManager,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly RequestStack $requestStack,
        private readonly RedisCacheService $redisCacheService
    )
    {
    }

    public function mount($query = '', $currentRoute = 'search'): void
    {
        $this->currentRoute = $currentRoute;
        $this->query = $query;
        $token = $this->tokenStorage->getToken();
        $this->npub = $token?->getUserIdentifier();

        $this->logger->info('SearchComponent mount called with query: "' . $this->query . '"');

        // Credits are only relevant for authenticated users, but search works for everyone
        if ($this->npub !== null) {
            try {
                $this->credits = $this->creditsManager->getBalance($this->npub);
                $this->logger->info($this->credits);
            } catch (InvalidArgumentException $e) {
                $this->logger->error($e);
                $this->credits = $this->creditsManager->resetBalance($this->npub);
            }
        }

        // If a query is provided (from URL or prop), perform the search automatically
        if (!empty($this->query)) {
            $this->logger->info('Query detected in mount, triggering search for: ' . $this->query);
            // Clear cache if this is a different query than what's cached
            $session = $this->requestStack->getSession();
            if ($session->has(self::SESSION_QUERY_KEY)) {
                $cachedQuery = $session->get(self::SESSION_QUERY_KEY);
                if ($cachedQuery !== $this->query) {
                    $this->clearSearchCache();
                    $this->logger->info('Cleared cache for different query. Old: ' . $cachedQuery . ', New: ' . $this->query);
                }
            }

            try {
                $this->search();
            } catch (InvalidArgumentException $e) {
                $this->logger->error('Search error on mount: ' . $e->getMessage());
            }
            return;
        }

        // Otherwise, restore search results from session if available
        if ($this->currentRoute == 'search') {
            $session = $this->requestStack->getSession();
            if ($session->has(self::SESSION_QUERY_KEY)) {
                $this->query = $session->get(self::SESSION_QUERY_KEY);
                $this->results = $session->get(self::SESSION_KEY, []);
                $pubkeys = array_unique(array_map(fn($art) => $art->getPubkey(), $this->results));
                $this->authors = $this->redisCacheService->getMultipleMetadata($pubkeys);
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
        $isAuthenticated = $this->npub !== null;

        $this->logger->info("Query: {$this->query}, npub: " . ($this->npub ?? 'anonymous'));

        if (empty($this->query)) {
            $this->results = [];
            $this->authors = [];
            $this->clearSearchCache();
            return;
        }

        // Update credits for authenticated users
        if ($isAuthenticated) {
            try {
                $this->credits = $this->creditsManager->getBalance($this->npub);
            } catch (InvalidArgumentException $e) {
                $this->credits = $this->creditsManager->resetBalance($this->npub);
            }
        }

        // Check if the same query exists in session (works for both auth and anon)
        $session = $this->requestStack->getSession();
        if ($session->has(self::SESSION_QUERY_KEY) &&
            $session->get(self::SESSION_QUERY_KEY) === $this->query) {
            $this->results = $session->get(self::SESSION_KEY, []);
            $pubkeys = array_unique(array_map(fn($art) => $art->getPubkey(), $this->results));
            $this->authors = $this->redisCacheService->getMultipleMetadata($pubkeys);
            $this->logger->info('Using cached search results for query: ' . $this->query);
            return;
        }

        try {
            $this->results = [];

            // Only spend credits if user is authenticated
            if ($isAuthenticated && $this->creditsManager->canAfford($this->npub, 1)) {
                $this->creditsManager->spendCredits($this->npub, 1, 'search');
                $this->credits--;
            }

            // Set result limits: 5 for anonymous, 12 for authenticated users
            $maxResults = $isAuthenticated ? 12 : 5;
            $this->logger->info('Search limit: ' . $maxResults . ' results for ' . ($isAuthenticated ? 'authenticated' : 'anonymous') . ' user');

            // Perform optimized single search query with appropriate limit
            $this->results = $this->performOptimizedSearch($this->query, $maxResults);
            $pubkeys = array_unique(array_map(fn($art) => $art->getPubkey(), $this->results));
            $this->authors = $this->redisCacheService->getMultipleMetadata($pubkeys);

            // Cache the search results in session
            $this->saveSearchToSession($this->query, $this->results);

        } catch (\Exception $e) {
            $this->logger->error('Search error: ' . $e->getMessage());
            $this->results = [];
            $this->authors = [];
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
     * Perform optimized single search query
     * @param string $query The search query
     * @param int|null $maxResults Maximum number of results (null for default)
     */
    private function performOptimizedSearch(string $query, ?int $maxResults = null): array
    {
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

        // Pagination - use maxResults if provided, otherwise use default resultsPerPage
        $effectiveResultsPerPage = $maxResults ?? $this->resultsPerPage;
        $offset = ($this->page - 1) * $effectiveResultsPerPage;
        $mainQuery->setFrom($offset);
        $mainQuery->setSize($effectiveResultsPerPage);

        // Execute the search
        $results = $this->finder->find($mainQuery);
        $this->logger->info('Search results count: ' . count($results));

        return $results;
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
