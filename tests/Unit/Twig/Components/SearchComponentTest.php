<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Dto\SearchFilters;
use App\Entity\Article;
use App\Service\Cache\RedisCacheService;
use App\Service\Search\ArticleSearchInterface;
use App\Twig\Components\SearchComponent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class SearchComponentTest extends TestCase
{
    public function testBlankUnfilteredSearchKeepsTheEmptyPrompt(): void
    {
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::never())->method('search');
        $search->expects(self::never())->method('advancedSearch');

        $component = $this->component($search);
        $component->query = '   ';
        $component->search();

        self::assertSame([], $component->results);
    }

    public function testBlankQueryWithTagsUsesAdvancedSearch(): void
    {
        $article = $this->article('tagged');
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::never())->method('search');
        $search->expects(self::once())
            ->method('advancedSearch')
            ->with(
                '',
                self::callback(static fn (SearchFilters $filters): bool => $filters->getTagsArray() === ['nostr']),
                12,
                0,
            )
            ->willReturn([$article]);

        $component = $this->component($search);
        $component->query = '   ';
        $component->filterTags = ' Nostr ';
        $component->search();

        self::assertSame([$article], $component->results);
    }

    public function testBlankQueryWithUppercaseHexAuthorUsesLowercaseFilter(): void
    {
        $article = $this->article('author-match');
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::never())->method('search');
        $search->expects(self::once())
            ->method('advancedSearch')
            ->with(
                '',
                self::callback(static fn (SearchFilters $filters): bool => $filters->author === str_repeat('a', 64)),
                12,
                0,
            )
            ->willReturn([$article]);

        $component = $this->component($search);
        $component->filterAuthor = str_repeat('A', 64);
        $component->search();

        self::assertSame([$article], $component->results);
        self::assertSame('', $component->filterError);
    }
    public function testLiteralZeroIsSearchedAsText(): void
    {
        $article = $this->article('zero');
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::once())->method('search')->with('0', 12, 0)->willReturn([$article]);
        $search->expects(self::never())->method('advancedSearch');

        $component = $this->component($search);
        $component->query = '0';
        $component->search();

        self::assertSame([$article], $component->results);
    }

    public function testChangingSortRequeriesTheSameTextAndResetsPage(): void
    {
        $newest = $this->article('newest');
        $oldest = $this->article('oldest');
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::once())->method('search')->with('nostr', 12, 0)->willReturn([$newest]);
        $search->expects(self::once())
            ->method('advancedSearch')
            ->with(
                'nostr',
                self::callback(static fn (SearchFilters $filters): bool => $filters->sortBy === 'oldest'),
                12,
                0,
            )
            ->willReturn([$oldest]);

        $component = $this->component($search);
        $component->query = 'nostr';
        $component->search();
        self::assertSame([$newest], $component->results);

        $component->filterSort = 'oldest';
        $component->page = 4;
        $component->search();

        self::assertSame(1, $component->page);
        self::assertSame([$oldest], $component->results);
    }

    public function testClearingOneDatePreservesTheOtherAndRefreshesResults(): void
    {
        $before = $this->article('before');
        $after = $this->article('after');
        $seenFilters = [];
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::exactly(2))
            ->method('advancedSearch')
            ->willReturnCallback(static function (string $query, SearchFilters $filters) use (&$seenFilters, $before, $after): array {
                $seenFilters[] = [$query, $filters->dateFrom, $filters->dateTo];

                return count($seenFilters) === 1 ? [$before] : [$after];
            });

        $component = $this->component($search);
        $component->filterDateFrom = '2026-09-01';
        $component->filterDateTo = '2026-09-25';
        $component->search();
        $component->clearDateFrom();

        self::assertSame('', $component->filterDateFrom);
        self::assertSame('2026-09-25', $component->filterDateTo);
        self::assertSame([
            ['', '2026-09-01', '2026-09-25'],
            ['', null, '2026-09-25'],
        ], $seenFilters);
        self::assertSame([$after], $component->results);
    }

    public function testClearingEndDateKeepsStartDateAndRefreshesResults(): void
    {
        $before = $this->article('before');
        $after = $this->article('after');
        $seenFilters = [];
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::exactly(2))
            ->method('advancedSearch')
            ->willReturnCallback(static function (string $query, SearchFilters $filters) use (&$seenFilters, $before, $after): array {
                $seenFilters[] = [$filters->dateFrom, $filters->dateTo];

                return count($seenFilters) === 1 ? [$before] : [$after];
            });

        $component = $this->component($search);
        $component->filterDateFrom = '2026-09-01';
        $component->filterDateTo = '2026-09-25';
        $component->search();
        $component->clearDateTo();

        self::assertSame('2026-09-01', $component->filterDateFrom);
        self::assertSame('', $component->filterDateTo);
        self::assertSame([
            ['2026-09-01', '2026-09-25'],
            ['2026-09-01', null],
        ], $seenFilters);
        self::assertSame([$after], $component->results);
    }
    public function testClearingAllFiltersRefreshesTextResults(): void
    {
        $filtered = $this->article('filtered');
        $unfiltered = $this->article('unfiltered');
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::once())->method('advancedSearch')->willReturn([$filtered]);
        $search->expects(self::once())->method('search')->with('nostr', 12, 0)->willReturn([$unfiltered]);

        $component = $this->component($search);
        $component->query = 'nostr';
        $component->filterTags = 'bitcoin';
        $component->search();
        $component->clearFilters();

        self::assertSame('', $component->filterTags);
        self::assertSame([$unfiltered], $component->results);
    }

    public function testInvalidAuthorShowsErrorWithoutIgnoringTheFilter(): void
    {
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::never())->method('search');
        $search->expects(self::never())->method('advancedSearch');

        $component = $this->component($search);
        $component->query = 'nostr';
        $component->filterAuthor = 'invalid-author';
        $component->search();

        self::assertSame('search.filters.invalidAuthor', $component->filterError);
        self::assertSame([], $component->results);
    }
    public function testMountRestoresVisibleCriteriaAndRequeriesResults(): void
    {
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $first = $this->article('first');
        $updated = $this->article('updated');
        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::exactly(2))
            ->method('advancedSearch')
            ->with(
                'nostr',
                self::callback(static fn (SearchFilters $filters): bool => $filters->sortBy === 'oldest'),
                12,
                0,
            )
            ->willReturnOnConsecutiveCalls([$first], [$updated]);

        $initial = $this->component($search, $requestStack);
        $initial->query = 'nostr';
        $initial->filterSort = 'oldest';
        $initial->showFilters = true;
        $initial->search();

        $restored = $this->component($search, $requestStack);
        $restored->mount();

        self::assertSame('nostr', $restored->query);
        self::assertSame('oldest', $restored->filterSort);
        self::assertTrue($restored->showFilters);
        self::assertSame([$updated], $restored->results);
    }
    public function testExplicitEmptyQueryDoesNotRestoreSessionCriteria(): void
    {
        $request = Request::create('/search?q=');
        $request->attributes->set('_route', 'app_search_index');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set('last_search_criteria', [
            'criteria' => [
                'query' => 'previous',
                'dateFrom' => '2026-09-01',
                'dateTo' => '',
                'author' => '',
                'tags' => '',
                'kind' => '',
                'sort' => 'oldest',
            ],
            'page' => 2,
            'showFilters' => true,
        ]);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $search = $this->createMock(ArticleSearchInterface::class);
        $search->expects(self::never())->method('search');
        $search->expects(self::never())->method('advancedSearch');

        $component = $this->component($search, $requestStack);
        $component->mount('', 'search');

        self::assertSame('', $component->query);
        self::assertSame('', $component->filterDateFrom);
        self::assertSame('relevance', $component->filterSort);
        self::assertSame([], $component->results);
        self::assertFalse($request->getSession()->has('last_search_criteria'));
    }
    private function component(ArticleSearchInterface $search, ?RequestStack $requestStack = null): SearchComponent
    {
        if ($requestStack === null) {
            $request = new Request();
            $request->setSession(new Session(new MockArraySessionStorage()));
            $requestStack = new RequestStack();
            $requestStack->push($request);
        }

        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->method('getMultipleMetadata')->willReturn([]);

        return new SearchComponent(
            $search,
            new NullLogger(),
            $requestStack,
            $metadata,
        );
    }

    private function article(string $slug): Article
    {
        return (new Article())->setPubkey(str_repeat('a', 64))->setSlug($slug);
    }
}
