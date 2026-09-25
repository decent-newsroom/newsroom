<?php

namespace App\Twig\Components;

use App\Dto\SearchFilters;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisCacheService;
use App\Service\Search\ArticleSearchInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class SearchComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp(writable: true, useSerializerForHydration: true)]
    public string $query = '';
    /** @var Article[] */
    public array $results = [];
    /** @var array<string, \stdClass> */
    public array $authors = [];

    public bool $interactive = true;
    public string $currentRoute;

    #[LiveProp]
    public int $vol = 0;

    #[LiveProp(writable: true)]
    public int $page = 1;

    #[LiveProp]
    public int $resultsPerPage = 12;

    // New: render results with add-to-list buttons when true
    #[LiveProp(writable: true)]
    public bool $selectMode = false;

    // ── Advanced Search Filters ──────────────────────────────────
    #[LiveProp(writable: true)]
    public bool $showFilters = false;

    #[LiveProp(writable: true)]
    public string $filterDateFrom = '';

    #[LiveProp(writable: true)]
    public string $filterDateTo = '';

    #[LiveProp(writable: true)]
    public string $filterAuthor = '';

    #[LiveProp(writable: true)]
    public string $filterTags = '';

    #[LiveProp(writable: true)]
    public string $filterKind = '';

    #[LiveProp(writable: true)]
    public string $filterSort = 'relevance';

    public string $filterError = '';

    private const SESSION_CRITERIA_KEY = 'last_search_criteria';
    private const LEGACY_SESSION_KEY = 'last_search_results';
    private const LEGACY_SESSION_QUERY_KEY = 'last_search_query';

    public function __construct(
        private readonly ArticleSearchInterface $articleSearch,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly RedisCacheService $redisCacheService
    ) {
    }

    public function mount(string $query = '', string $currentRoute = 'search'): void
    {
        $this->currentRoute = $currentRoute;
        $this->query = trim((string) $query);

        $request = $this->requestStack->getCurrentRequest();
        $explicitQuery = $request?->attributes->get('_route') === 'app_search_index'
            && $request->query->has('q');

        if ($this->query !== '' || $explicitQuery) {
            if ($this->query === '') {
                $this->forgetSearchCriteria();
                return;
            }

            $this->search();
            return;
        }

        if ($this->currentRoute !== 'search') {
            return;
        }

        $saved = $this->requestStack->getSession()->get(self::SESSION_CRITERIA_KEY);
        if (!is_array($saved) || !is_array($saved['criteria'] ?? null)) {
            return;
        }

        $criteria = $saved['criteria'];
        $this->query = (string) ($criteria['query'] ?? '');
        $this->filterDateFrom = (string) ($criteria['dateFrom'] ?? '');
        $this->filterDateTo = (string) ($criteria['dateTo'] ?? '');
        $this->filterAuthor = (string) ($criteria['author'] ?? '');
        $this->filterTags = (string) ($criteria['tags'] ?? '');
        $this->filterKind = (string) ($criteria['kind'] ?? '');
        $this->filterSort = (string) ($criteria['sort'] ?? 'relevance');
        $this->page = max(1, (int) ($saved['page'] ?? 1));
        $this->showFilters = (bool) ($saved['showFilters'] ?? $this->hasActiveFilters());

        if ($this->query !== '' || $this->hasActiveFilters()) {
            $this->search();
        }
    }

    /**
     * Run the current query and filters. Only a completely blank search remains empty.
     */
    #[LiveAction]
    public function search(): ?Response
    {
        $this->query = trim($this->query);
        $this->filterError = '';

        if ($this->query === '' && !$this->hasActiveFilters()) {
            $this->results = [];
            $this->authors = [];
            $this->page = 1;
            $this->forgetSearchCriteria();
            return null;
        }

        try {
            $filters = $this->buildFilters();
        } catch (\InvalidArgumentException $e) {
            $this->filterError = $e->getMessage();
            $this->results = [];
            $this->authors = [];
            return null;
        }

        // Nostr identifiers keep their existing redirect behavior.
        $identifier = $this->query;
        if (str_starts_with($identifier, 'nostr:')) {
            $identifier = substr($identifier, 6);
        }
        foreach (['npub1' => 'npub', 'naddr1' => 'naddr', 'nevent1' => 'nevent', 'note1' => 'note', 'nprofile1' => 'nprofile', 'nsec1' => 'nsec'] as $prefix => $type) {
            if (!str_starts_with($identifier, $prefix)) {
                continue;
            }
            return match ($type) {
                'npub' => $this->redirectToRoute('author-profile', ['npub' => $identifier]),
                'naddr', 'nevent', 'note', 'nprofile' => $this->redirectToRoute('nevent', ['nevent' => $identifier]),
                default => null,
            };
        }

        $session = $this->requestStack->getSession();
        $previous = $session->get(self::SESSION_CRITERIA_KEY);
        if (is_array($previous) && ($previous['criteria'] ?? null) !== $this->criteriaSignature()) {
            $this->page = 1;
        }
        $this->page = max(1, $this->page);

        try {
            $this->results = $this->performOptimizedSearch($this->query, $filters);
            $pubkeys = array_unique(array_map(fn($article) => $article->getPubkey(), $this->results));
            $metadataMap = $pubkeys === [] ? [] : $this->redisCacheService->getMultipleMetadata($pubkeys);
            $this->authors = array_map(fn($metadata) => $metadata->toStdClass(), $metadataMap);
            $this->saveSearchCriteria();
        } catch (\Exception $e) {
            $this->logger->error('Search error: ' . $e->getMessage());
            $this->results = [];
            $this->authors = [];
        }

        return null;
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
     * @return Article[]
     */
    private function performOptimizedSearch(string $query, SearchFilters $filters, ?int $maxResults = null): array
    {
        $effectiveResultsPerPage = $maxResults ?? $this->resultsPerPage;
        $offset = ($this->page - 1) * $effectiveResultsPerPage;

        if ($filters->hasActiveFilters()) {
            $results = $this->articleSearch->advancedSearch($query, $filters, $effectiveResultsPerPage, $offset);
        } else {
            $results = $this->articleSearch->search($query, $effectiveResultsPerPage, $offset);
        }

        $this->logger->info('Search results count: ' . count($results));

        return $results;
    }

    /**
     * Build validated filters from the current LiveProp values.
     */
    private function buildFilters(): SearchFilters
    {
        $dateFrom = trim($this->filterDateFrom);
        $dateTo = trim($this->filterDateTo);
        foreach ([$dateFrom, $dateTo] as $date) {
            if ($date !== '' && (!$this->isValidDate($date))) {
                throw new \InvalidArgumentException('search.filters.invalidDateRange');
            }
        }
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            throw new \InvalidArgumentException('search.filters.invalidDateRange');
        }

        if (!in_array($this->filterSort, ['relevance', 'newest', 'oldest'], true)) {
            throw new \InvalidArgumentException('search.filters.invalidSort');
        }

        $authorHex = null;
        $author = strtolower(trim($this->filterAuthor));
        if ($author !== '') {
            if (str_starts_with($author, 'nostr:')) {
                $author = substr($author, 6);
            }
            try {
                $publicKey = str_starts_with($author, 'npub1')
                    ? PublicKey::fromBech32($author)
                    : PublicKey::fromHex($author);
            } catch (\InvalidArgumentException) {
                $publicKey = null;
            }
            if ($publicKey === null) {
                throw new \InvalidArgumentException('search.filters.invalidAuthor');
            }
            $authorHex = strtolower($publicKey->toHex());
        }

        $tags = trim($this->filterTags);
        $kind = trim($this->filterKind);

        return new SearchFilters(
            dateFrom: $dateFrom !== '' ? $dateFrom : null,
            dateTo: $dateTo !== '' ? $dateTo : null,
            author: $authorHex,
            tags: $tags !== '' ? $tags : null,
            kind: $kind !== '' ? (int) $kind : null,
            sortBy: $this->filterSort,
        );
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    #[LiveAction]
    public function toggleFilters(): void
    {
        $this->showFilters = !$this->showFilters;
        $this->search();
    }

    #[LiveAction]
    public function clearDateFrom(): void
    {
        $this->filterDateFrom = '';
        $this->page = 1;
        $this->search();
    }

    #[LiveAction]
    public function clearDateTo(): void
    {
        $this->filterDateTo = '';
        $this->page = 1;
        $this->search();
    }

    #[LiveAction]
    public function clearFilters(): void
    {
        $this->filterDateFrom = '';
        $this->filterDateTo = '';
        $this->filterAuthor = '';
        $this->filterTags = '';
        $this->filterKind = '';
        $this->filterSort = 'relevance';
        $this->page = 1;
        $this->search();
    }

    /**
     * Returns the available content kind options for the filter dropdown.
     * Only published (indexable) kinds are listed — drafts are excluded from the index.
     * @return array<array{value: int, label: string}>
     */
    public function getKindOptions(): array
    {
        return [
            ['value' => KindsEnum::LONGFORM->value, 'label' => 'Article'],
        ];
    }

    /**
     * Checks whether any advanced filter is currently active.
     */
    public function hasActiveFilters(): bool
    {
        return trim($this->filterDateFrom) !== ''
            || trim($this->filterDateTo) !== ''
            || trim($this->filterAuthor) !== ''
            || trim($this->filterTags) !== ''
            || trim($this->filterKind) !== ''
            || $this->filterSort !== 'relevance';
    }

    /**
     * Keep the criteria needed to reproduce the view, never serialized result objects.
     */
    /** @return array{query: string, dateFrom: string, dateTo: string, author: string, tags: string, kind: string, sort: string} */
    /** @return array{query: string, dateFrom: string, dateTo: string, author: string, tags: string, kind: string, sort: string} */
    private function criteriaSignature(): array
    {
        return [
            'query' => $this->query,
            'dateFrom' => trim($this->filterDateFrom),
            'dateTo' => trim($this->filterDateTo),
            'author' => trim($this->filterAuthor),
            'tags' => trim($this->filterTags),
            'kind' => trim($this->filterKind),
            'sort' => $this->filterSort,
        ];
    }

    private function saveSearchCriteria(): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_CRITERIA_KEY, [
            'criteria' => $this->criteriaSignature(),
            'page' => $this->page,
            'showFilters' => $this->showFilters,
        ]);
        $session->remove(self::LEGACY_SESSION_KEY);
        $session->remove(self::LEGACY_SESSION_QUERY_KEY);
    }

    private function forgetSearchCriteria(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_CRITERIA_KEY);
        $session->remove(self::LEGACY_SESSION_KEY);
        $session->remove(self::LEGACY_SESSION_QUERY_KEY);
    }
}
