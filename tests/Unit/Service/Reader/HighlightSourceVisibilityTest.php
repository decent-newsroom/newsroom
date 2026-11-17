<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Highlight;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\HighlightRepository;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\HighlightSourceVisibility;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use Innis\Nostr\Core\Domain\Entity\Event as NostrCoreEvent;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

final class HighlightSourceVisibilityTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testGuestsSeeOnlyLocallyResolvableSourcesAndCachedReferencesAreReconstructed(): void
    {
        $chapter = '30041:' . self::AUTHOR . ':Part:One';
        $article = '30023:' . self::AUTHOR . ':Essay:One';
        $missingArticle = '30023:' . self::AUTHOR . ':missing';
        $wrongCoordinate = '30040:' . self::AUTHOR . ':wrong';
        $relay = 'wss://relay.example/CaseSensitive/';
        $exact = self::id(100);
        $missingExact = self::id(101);
        $stored = [
            self::id(1) => $this->event(self::id(1), 9802, [['e', strtoupper($exact)], ['a', $chapter]]),
            self::id(2) => $this->event(self::id(2), 9802, [['a', $chapter], ['e', $missingExact]]),
            self::id(3) => $this->event(self::id(3), 9802, [['a', $chapter, $relay]]),
            self::id(4) => $this->event(self::id(4), 9802, [['a', $article]]),
            self::id(5) => $this->event(self::id(5), 9802, [['a', $missingArticle]]),
            self::id(6) => $this->event(self::id(6), 9802, [['r', 'https://example.test/article']]),
            self::id(7) => $this->event(self::id(7), 9802, [['E', $missingExact], ['a', $chapter, $relay]]),
            self::id(8) => $this->event(self::id(8), 9802, [['a', $wrongCoordinate]]),
            self::id(10) => $this->event(self::id(10), 9802, [['e', 'invalid'], ['a', 'invalid']]),
        ];
        $projection = (new Highlight())->setEventId(self::id(9))->setRawEvent(['tags' => [['e', $exact]]]);
        $chapterEvent = $this->event(self::id(200), 30041, [['d', 'Part:One']]);
        $wrongEvent = $this->event(self::id(201), 30040, [['d', 'different'], ['d', 'wrong']]);
        $articleRaw = NostrCoreEvent::fromArray([
            'pubkey' => self::AUTHOR,
            'created_at' => 123,
            'kind' => KindsEnum::LONGFORM->value,
            'tags' => [['d', 'Essay:One']],
            'content' => 'Article body',
        ])->toArray();
        $articleRaw['sig'] = str_repeat('c', 128);
        $articleEntity = (new Article())->setKind(KindsEnum::LONGFORM)->setEventId($articleRaw['id'])
            ->setPubkey(self::AUTHOR)->setSlug('Essay:One')->setCreatedAt(123);
        $articleEntity->setRaw($articleRaw);

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByIds')->willReturn($stored);
        $events->expects(self::once())->method('findHighlightSourceTargets')
            ->willReturnCallback(function (array $coordinates, array $eventIds) use ($chapter, $wrongCoordinate, $chapterEvent, $wrongEvent, $exact): array {
                self::assertContains($exact, $eventIds);
                self::assertContains(self::id(101), $eventIds);
                self::assertContains($chapter, $coordinates);
                return [
                    'eventIds' => [$exact => $this->event($exact, 1, [])],
                    'coordinates' => [$chapter => $chapterEvent, $wrongCoordinate => $wrongEvent],
                ];
            });
        $highlights = $this->createMock(HighlightRepository::class);
        $highlights->expects(self::once())->method('findBy')
            ->with(['eventId' => [self::id(9)]])->willReturn([$projection]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findByCoordinates')
            ->willReturn([$article => $articleEntity]);
        $articles->expects(self::once())->method('findHighlightSourcesByEventIds')
            ->with([$articleRaw['id']])->willReturn([$articleRaw['id'] => [$articleEntity]]);
        $visibility = $this->visibility($events, $highlights, $articles);

        $items = [];
        for ($i = 1; $i <= 10; ++$i) {
            $items[] = ['id' => self::id($i), 'content' => 'passage ' . $i, 'article_ref' => null];
        }
        // This cache entry looks like an Article but its actual source is missing.
        $items[4]['article_ref'] = $missingArticle;
        $items[4]['article_title'] = 'Stale placeholder';
        // The profile worker's older cache schema omits all source fields.
        $items[6] = (object) ['id' => self::id(7), 'content' => 'profile passage'];

        $result = $visibility->filter($items);
        self::assertSame(
            [self::id(1), self::id(3), self::id(4), self::id(7), self::id(9)],
            array_map(static fn (array|object $item): string => ((array) $item)['id'], $result),
        );
        self::assertSame($exact, $result[0]['event_ref']);
        self::assertSame($chapter, $result[1]['article_ref']);
        self::assertInstanceOf(\stdClass::class, $result[3]);
        self::assertSame($chapter, $result[3]->article_ref);
        self::assertNull($result[3]->event_ref);
        $address = new Bech32($result[3]->naddr);
        self::assertSame(30041, $address->data->kind);
        self::assertSame('Part:One', $address->data->identifier);
        self::assertSame([$relay], $address->data->relays);
        self::assertSame($exact, $result[4]['event_ref']);
    }

    public function testSignedInReadersKeepAllItemsWithoutSourceLookups(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByIds');
        $events->expects(self::never())->method('findHighlightSourceTargets');
        $highlights = $this->createMock(HighlightRepository::class);
        $highlights->expects(self::never())->method('findBy');
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::never())->method('findByCoordinates');
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $visibility = $this->makeHighlightVisibility($events, $highlights, $articles, $security);
        $items = [['id' => self::id(1), 'article_ref' => null], (object) ['id' => self::id(2)]];

        self::assertSame($items, $visibility->filter($items));
        self::assertTrue($visibility->isSourceAvailable([]));
    }

    public function testDirectPageDoesNotFallThroughAMalformedFirstAddress(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findHighlightSourceTargets');
        $visibility = $this->visibility($events);

        self::assertFalse($visibility->isSourceAvailable([
            ['a', 'invalid'],
            ['a', '30040:' . self::AUTHOR . ':valid'],
        ]));
        self::assertFalse($visibility->isSourceAvailable([['r', 'https://example.test/']]));
        self::assertFalse($visibility->isSourceAvailable([['E', self::id(100)]]));
    }

    public function testDatabaseFailuresAreNotConvertedIntoAnEmptySuccessfulFeed(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findByIds')->willThrowException(new \RuntimeException('database unavailable'));
        $visibility = $this->visibility($events);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');
        $visibility->filter([['id' => self::id(1)]]);
    }

    public function testGuestSourcesMustPassExistingPreviewAccessAndScopeRules(): void
    {
        $blockedAuthor = str_repeat('b', 64);
        $sourceEvents = [
            1 => $this->event(self::id(1001), 1, []),
            2 => $this->event(self::id(1002), 1, [['content-warning', 'sensitive']]),
            3 => $this->event(self::id(1003), 1, [['h', 'private-group']]),
            4 => $this->event(self::id(1004), 42, []),
            5 => $this->event(self::id(1005), 1, [], $blockedAuthor),
            6 => $this->event(self::id(1006), 1, []),
            7 => $this->event(self::id(1007), 30040, [['d', 'hidden']]),
            8 => $this->event(strtoupper(self::id(1008)), 1, []),
        ];
        $highlights = $targets = $items = [];
        foreach ($sourceEvents as $number => $source) {
            $highlightId = self::id($number);
            $sourceId = self::id(1000 + $number);
            $highlights[$highlightId] = $this->event($highlightId, 9802, [['e', $sourceId]]);
            $targets[$sourceId] = $source;
            $items[] = ['id' => $highlightId];
        }

        $events = $this->createMock(EventRepository::class);
        $events->method('findByIds')->willReturn($highlights);
        $events->method('findHighlightSourceTargets')
            ->willReturn(['eventIds' => $targets, 'coordinates' => []]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn([$blockedAuthor]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('findSuppressedEventIds')->willReturn([self::id(1006)]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturn(['30040:' . self::AUTHOR . ':hidden']);
        $security = $this->createMock(Security::class);
        $access = $this->makeHighlightPreviewAccess($security, $authors, $deletions, $hidden);
        $visibility = $this->makeHighlightVisibility($events, security: $security, access: $access);

        self::assertSame([self::id(1)], array_column($visibility->filter($items), 'id'));
    }

    public function testGuestLongformSourcesRequireTheMatchingReadableArticleProjection(): void
    {
        $highlights = $targets = $items = $projections = [];
        foreach ([
            1 => 'valid',
            2 => 'missing',
            3 => 'exclusive',
            4 => 'wrong-slug',
            5 => 'wrong-signed-d',
            6 => 'wrong-timestamp',
            7 => 'wrong-author',
            8 => 'sensitive',
            9 => 'wrong-event',
            10 => 'scoped',
            11 => 'duplicate-valid',
            12 => 'duplicate-draft',
            13 => 'duplicate-exclusive',
        ] as $number => $slug) {
            $highlightId = self::id($number);
            $sourceId = self::id(2000 + $number);
            $highlights[$highlightId] = $this->event($highlightId, 9802, [['e', $sourceId]]);
            $targets[$sourceId] = $this->event($sourceId, 30023, [['d', $slug]], createdAt: 123);
            $items[] = ['id' => $highlightId];
            if ($number !== 2) {
                $projections[$sourceId] = $this->article($sourceId, $slug);
            }
        }
        $projections[self::id(2003)]->setEssayistExclusive(true);
        $projections[self::id(2004)]->setSlug('different');
        $projections[self::id(2005)]->setRaw(['tags' => [['d', 'different'], ['d', 'wrong-signed-d']]]);
        $projections[self::id(2006)]->setCreatedAt(124);
        $projections[self::id(2007)]->setPubkey(str_repeat('b', 64));
        $projections[self::id(2008)]->setRaw(['tags' => [['d', 'sensitive'], ['content-warning', 'warning']]]);
        $projections[self::id(2009)]->setEventId(self::id(2999));
        $projections[self::id(2010)]->setRaw(['tags' => [['d', 'scoped'], ['h', 'private-group']]]);
        $projections = array_map(static fn (Article $article): array => [$article], $projections);
        $projections[self::id(2011)][] = $this->article(self::id(2011), 'duplicate-valid');
        $projections[self::id(2012)][] = $this->article(self::id(2012), 'duplicate-draft')
            ->setKind(KindsEnum::LONGFORM_DRAFT);
        $projections[self::id(2013)][] = $this->article(self::id(2013), 'duplicate-exclusive')
            ->setEssayistExclusive(true);

        $events = $this->createMock(EventRepository::class);
        $events->method('findByIds')->willReturn($highlights);
        $events->method('findHighlightSourceTargets')
            ->willReturn(['eventIds' => $targets, 'coordinates' => []]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findHighlightSourcesByEventIds')->willReturn($projections);
        $visibility = $this->visibility($events, articles: $articles);

        self::assertSame([self::id(1), self::id(11)], array_column($visibility->filter($items), 'id'));
    }

    public function testExistingLongformEventDoesNotFallBackToAnotherArticleRevision(): void
    {
        $coordinate = '30023:' . self::AUTHOR . ':article';
        $events = $this->createMock(EventRepository::class);
        $events->method('findHighlightSourceTargets')->willReturn([
            'eventIds' => [],
            'coordinates' => [$coordinate => $this->event(self::id(3000), 30023, [['d', 'article']], createdAt: 123)],
        ]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findHighlightSourcesByEventIds')->willReturn([]);
        $articles->expects(self::never())->method('findByCoordinates');

        self::assertFalse($this->visibility($events, articles: $articles)->isSourceAvailable([['a', $coordinate]]));
    }

    /** @dataProvider articleOnlySources */
    public function testArticleOnlySourceRequiresAVerifiedAndAuthorizedPreview(
        bool $validSignature,
        bool $exclusive,
        bool $mismatchedEnvelope,
        bool $expected,
    ): void {
        $coordinate = '30023:' . self::AUTHOR . ':article';
        $raw = NostrCoreEvent::fromArray([
            'pubkey' => self::AUTHOR,
            'created_at' => 123,
            'kind' => KindsEnum::LONGFORM->value,
            'tags' => [['d', 'article']],
            'content' => 'Article body',
        ])->toArray();
        $raw['sig'] = str_repeat('c', 128);
        $article = $this->article($raw['id'], 'article');
        $article->setEssayistExclusive($exclusive);
        if ($mismatchedEnvelope) {
            $raw['id'] = self::id(9999);
        }
        $article->setRaw($raw);
        $events = $this->createMock(EventRepository::class);
        $events->method('findHighlightSourceTargets')->willReturn(['eventIds' => [], 'coordinates' => []]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findByCoordinates')->willReturn([$coordinate => $article]);
        $articles->method('findHighlightSourcesByEventIds')->willReturn([$article->getEventId() => [$article]]);
        $visibility = $this->makeHighlightVisibility(
            $events, articles: $articles, verifier: $this->makeHighlightVerifier($validSignature),
        );

        $source = $visibility->prepareEventSource([['a', $coordinate]]);
        self::assertSame($expected, $source !== null);
        if ($source !== null) {
            self::assertSame($coordinate, $source['article_ref']);
            self::assertSame('article', (new Bech32($source['naddr']))->data->identifier);
        }
    }

    public static function articleOnlySources(): iterable
    {
        yield 'verified' => [true, false, false, true];
        yield 'invalid signature' => [false, false, false, false];
        yield 'exclusive' => [true, true, false, false];
        yield 'mismatched envelope' => [true, false, true, false];
    }

    /** @dataProvider duplicateArticleOnlySources */
    public function testArticleOnlySourceChecksEveryAmbiguousProjection(bool $conflicting, bool $expected): void
    {
        $coordinate = '30023:' . self::AUTHOR . ':article';
        $raw = NostrCoreEvent::fromArray([
            'pubkey' => self::AUTHOR,
            'created_at' => 123,
            'kind' => KindsEnum::LONGFORM->value,
            'tags' => [['d', 'article']],
            'content' => 'Article body',
        ])->toArray();
        $raw['sig'] = str_repeat('c', 128);
        $article = $this->article($raw['id'], 'article');
        $article->setRaw($raw);
        $duplicate = $this->article($raw['id'], 'article')->setEssayistExclusive($conflicting);
        $duplicate->setRaw($raw);
        $events = $this->createMock(EventRepository::class);
        $events->method('findHighlightSourceTargets')->willReturn(['eventIds' => [], 'coordinates' => []]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findByCoordinates')->willReturn([$coordinate => $article]);
        $articles->expects(self::once())->method('findHighlightSourcesByEventIds')
            ->with([$raw['id']])->willReturn([$raw['id'] => [$article, $duplicate]]);

        self::assertSame($expected, $this->visibility($events, articles: $articles)->isSourceAvailable([['a', $coordinate]]));
    }

    public static function duplicateArticleOnlySources(): iterable
    {
        yield 'both readable' => [false, true];
        yield 'conflicting exclusive flag' => [true, false];
    }

    public function testLookupsAreBoundedAndPreserveInputOrderAcrossBatches(): void
    {
        $items = $stored = $targets = [];
        for ($i = 1; $i <= 260; ++$i) {
            $highlightId = self::id($i);
            $sourceId = self::id($i + 1000);
            $items[] = ['id' => $highlightId];
            $stored[$highlightId] = $this->event($highlightId, 9802, [['e', $sourceId]]);
            $targets[$sourceId] = $this->event($sourceId, 1, []);
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::exactly(3))->method('findByIds')
            ->willReturnCallback(static fn (array $ids): array => array_intersect_key($stored, array_flip($ids)));
        $events->expects(self::exactly(3))->method('findHighlightSourceTargets')
            ->willReturnCallback(static function (array $coordinates, array $ids) use ($targets): array {
                self::assertSame([], $coordinates);
                self::assertLessThanOrEqual(128, count($ids));
                return ['coordinates' => [], 'eventIds' => array_intersect_key($targets, array_flip($ids))];
            });
        $visibility = $this->visibility($events);

        self::assertSame(array_column($items, 'id'), array_column($visibility->filter($items), 'id'));
    }

    private function visibility(
        EventRepository $events,
        ?HighlightRepository $highlights = null,
        ?ArticleRepository $articles = null,
    ): HighlightSourceVisibility {
        return $this->makeHighlightVisibility($events, $highlights, $articles);
    }

    private function event(
        string $id,
        int $kind,
        array $tags,
        string $author = self::AUTHOR,
        int $createdAt = 0,
    ): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($author);
        $event->setKind($kind);
        $event->setTags($tags);
        $event->setCreatedAt($createdAt);
        return $event;
    }

    private function article(string $id, string $slug): Article
    {
        $article = (new Article())->setEventId($id)->setKind(KindsEnum::LONGFORM)
            ->setPubkey(self::AUTHOR)->setSlug($slug)->setCreatedAt(123);
        $article->setRaw(['tags' => [['d', $slug]]]);

        return $article;
    }

    private static function id(int $number): string
    {
        return str_pad(dechex($number), 64, '0', STR_PAD_LEFT);
    }
}
