<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\FeaturedBookshelf;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryUnavailableException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FeaturedBookshelfTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const BOOK_AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SOURCE = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const BOOK = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private EventRepository&MockObject $events;
    private MercuryApiClientInterface&MockObject $api;
    private ContentAuthorAccessPolicy&MockObject $authors;
    private HiddenCoordinateRepository&MockObject $hidden;
    private LoggerInterface&MockObject $logger;
    private NostrNip19Service $nip19;
    private ?Event $source;
    /** @var array<string, mixed>|null */
    private ?array $criteria = null;

    protected function setUp(): void
    {
        $this->nip19 = new NostrNip19Service();
        $this->events = $this->createMock(EventRepository::class);
        $this->events->method('findOneBy')->willReturnCallback(function (array $criteria, array $order): ?Event {
            $this->criteria = $criteria;
            self::assertSame(['created_at' => 'DESC', 'id' => 'ASC'], $order);

            return $this->source;
        });
        $this->api = $this->createMock(MercuryApiClientInterface::class);
        $this->api->expects(self::never())->method('getEventsByIds');
        $this->api->expects(self::never())->method('getChaptersByAuthors');
        $this->api->expects(self::never())->method('searchPublications');
        $this->authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $this->hidden = $this->createMock(HiddenCoordinateRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->source = new Event();
        $this->source->setId(self::SOURCE);
        $this->source->setKind(30045);
        $this->source->setPubkey(self::AUTHOR);
        $this->source->setCreatedAt(123);
        $this->source->setTags([['d', 'selected:Books']]);
    }

    public function testUnconfiguredSelectionDoesNoWork(): void
    {
        $this->events->expects(self::never())->method('findOneBy');
        $this->noMetadataRequests();
        self::assertSame('unconfigured', $this->service('')->page()['state']);
    }

    /** @dataProvider invalidAddresses */
    public function testInvalidConfigurationIsLoggedWithoutBreakingThePage(string $address): void
    {
        $this->events->expects(self::never())->method('findOneBy');
        $this->logger->expects(self::once())->method('error');
        $this->noMetadataRequests();
        self::assertSame('unavailable', $this->service($address)->page()['state']);
    }

    public static function invalidAddresses(): iterable
    {
        yield ['not-an-address'];
        yield ['naddr1invalid'];
        yield [(new NostrNip19Service())->encodeAddr(self::AUTHOR, 'wrong-kind', 30040)];
    }

    public function testExactDirectoryCoordinateAndRevisionAreUsed(): void
    {
        $this->noMetadataRequests();
        $page = $this->service()->page(page: 2, eventId: self::SOURCE);
        self::assertSame([
            'kind' => 30045, 'pubkey' => self::AUTHOR, 'dTag' => 'selected:Books', 'id' => self::SOURCE,
        ], $this->criteria);
        self::assertSame(self::SOURCE, $page['eventId']);
        self::assertSame(2, $page['page']);
        self::assertTrue($page['hasPrevious']);
    }

    public function testMissingDirectoryDoesNotRequestBooks(): void
    {
        $this->source = null;
        $this->noMetadataRequests();
        self::assertSame('unavailable', $this->service()->page()['state']);
    }

    /** @dataProvider sourceRestrictions */
    public function testRestrictedDirectoriesDoNotExposeReferences(string $restriction): void
    {
        $this->source->setTags([['d', 'selected:Books'], ['e', self::BOOK]]);
        switch ($restriction) {
            case 'encrypted': $this->source->setContent('encrypted private payload'); break;
            case 'scoped': $this->source->setTags([['d', 'selected:Books'], ['h', 'private']]); break;
            case 'sensitive': $this->source->setTags([['d', 'selected:Books'], ['content-warning', '']]); break;
            case 'author': $this->authors->method('isSuppressed')->willReturn(true); break;
            case 'hidden': $this->hidden->method('isHidden')->willReturn(true); break;
            case 'identity': $this->source->setTags([['d', 'different']]); break;
        }
        $this->noMetadataRequests();
        $page = $this->service()->page();
        self::assertSame('unavailable', $page['state']);
        self::assertNull($page['eventId']);
        self::assertSame([], $page['entries']);
    }

    public static function sourceRestrictions(): iterable
    {
        foreach (['encrypted', 'scoped', 'sensitive', 'author', 'hidden', 'identity'] as $case) {
            yield $case => [$case];
        }
    }

    public function testPreviewBoundsRequestsBeforeResolutionAndPreservesDuplicates(): void
    {
        $tags = [['d', 'selected:Books']];
        for ($i = 0; $i < 9; ++$i) {
            $tags[] = ['a', '30040:' . self::BOOK_AUTHOR . ':book', 'wss://hint.example'];
        }
        $this->source->setTags($tags);
        $this->api->expects(self::never())->method('getPublicationEventsByIds');
        $this->api->expects(self::once())->method('getPublicationsByAuthors')
            ->with([self::BOOK_AUTHOR], 50, ['book'])->willReturn([$this->book()]);
        $page = $this->service()->page(preview: true);
        self::assertCount(6, $page['entries']);
        self::assertTrue($page['hasNext']);
        foreach ($page['entries'] as $entry) {
            self::assertSame('available', $entry['status']);
            self::assertSame(self::BOOK, $entry['book']->eventId);
            self::assertNull($entry['book']->textContent);
        }
    }

    public function testSeventhDistinctBookIsNotRequestedForTheLandingPreview(): void
    {
        $ids = array_map(static fn (int $i): string => sprintf('%064x', $i), range(1, 7));
        $this->source->setTags([['d', 'selected:Books'], ...array_map(static fn (string $id): array => ['e', $id], $ids)]);
        $this->api->expects(self::once())->method('getPublicationEventsByIds')->with(array_slice($ids, 0, 6))->willReturn([]);
        $this->api->expects(self::never())->method('getPublicationsByAuthors');
        self::assertCount(6, $this->service()->page(preview: true)['entries']);
    }

    public function testEmptyIdentifierDoesNotBecomeAnAuthorWideApiSearch(): void
    {
        $this->source->setTags([['d', 'selected:Books'], ['a', '30040:' . self::BOOK_AUTHOR . ':']]);
        $this->noMetadataRequests();
        self::assertSame(['missing'], array_column($this->service()->page()['entries'], 'status'));
    }

    public function testRequestedRevisionIsNeverReplacedWithLatestDirectory(): void
    {
        $this->noMetadataRequests();
        $page = $this->service()->page(page: 2, eventId: str_repeat('e', 64));
        self::assertSame('unavailable', $page['state']);
        self::assertNull($page['eventId']);
        self::assertSame(str_repeat('e', 64), $this->criteria['id']);
    }

    public function testExactPinsNeverFallBackToCoordinateAndMustMatchAuthorAndIdentifier(): void
    {
        $other = str_repeat('e', 64);
        $this->source->setTags([
            ['d', 'selected:Books'],
            ['a', '30040:' . self::BOOK_AUTHOR . ':book', '', self::BOOK],
            ['a', '30040:' . self::BOOK_AUTHOR . ':book', '', $other],
            ['a', '30040:' . self::AUTHOR . ':book', '', self::BOOK],
            ['a', '30040:' . self::BOOK_AUTHOR . ':book', '', 'invalid-pin'],
            ['e', self::BOOK],
        ]);
        $this->api->expects(self::once())->method('getPublicationEventsByIds')
            ->with([self::BOOK, $other])->willReturn([$this->book()]);
        $this->api->expects(self::never())->method('getPublicationsByAuthors');
        self::assertSame(['available', 'missing', 'missing', 'invalid', 'available'],
            array_column($this->service()->page()['entries'], 'status'));
    }

    public function testLatestCoordinateRevisionUsesTimestampThenLowestEventIdAndExactCaseSensitiveIdentifier(): void
    {
        $this->source->setTags([['d', 'selected:Books'], ['a', '30040:' . self::BOOK_AUTHOR . ':Book:1']]);
        $events = [
            $this->book(self::BOOK, 'Book:1', 200),
            $this->book(str_repeat('f', 64), 'Book:1', 200),
            $this->book(str_repeat('e', 64), 'Book:1', 100),
            $this->book(str_repeat('1', 64), 'book:1', 300),
        ];
        $this->api->expects(self::once())->method('getPublicationsByAuthors')
            ->with([self::BOOK_AUTHOR], 50, ['Book:1'])->willReturn($events);
        $page = $this->service()->page();
        self::assertSame(self::BOOK, $page['entries'][0]['book']->eventId);
    }

    /** @dataProvider targetRestrictions */
    public function testApiTargetsAreIndependentlyAuthorized(string $restriction): void
    {
        $tags = [['d', 'book'], ['title', 'secret title'], ['summary', 'secret summary'], ['image', 'https://secret.example/cover']];
        switch ($restriction) {
            case 'scoped': $tags[] = ['s', 'private']; break;
            case 'sensitive': $tags[] = ['content-warning', '']; break;
            case 'author': $this->authors->method('isSuppressed')->willReturnCallback(fn (string $key): bool => $key === self::BOOK_AUTHOR); break;
            case 'hidden': $this->hidden->method('isHidden')->willReturnCallback(fn (string $coordinate): bool => str_starts_with($coordinate, '30040:')); break;
        }
        $this->source->setTags([['d', 'selected:Books'], ['e', self::BOOK]]);
        $this->api->method('getPublicationEventsByIds')->willReturn([
            new MercuryEvent(self::BOOK, self::BOOK_AUTHOR, 30040, 100, '', '', $tags),
        ]);
        $page = $this->service()->page();
        self::assertSame('restricted', $page['entries'][0]['status']);
        self::assertNull($page['entries'][0]['book']);
        self::assertStringNotContainsString('secret', serialize($page));
    }

    public static function targetRestrictions(): iterable
    {
        foreach (['scoped', 'sensitive', 'author', 'hidden'] as $case) {
            yield $case => [$case];
        }
    }

    public function testEmptyMissingAndInvalidEntriesAreExplicit(): void
    {
        self::assertSame([], $this->service()->page()['entries']);
        $this->source->setTags([['d', 'selected:Books'], ['e', 'bad'], ['e', self::BOOK], ['a', '30041:' . self::BOOK_AUTHOR . ':chapter']]);
        $this->api->method('getPublicationEventsByIds')->willReturn([]);
        self::assertSame(['invalid', 'missing'], array_column($this->service()->page()['entries'], 'status'));
    }

    public function testApiFailureIsLoggedAndNotDisguisedAsAnEmptyDirectory(): void
    {
        $this->source->setTags([['d', 'selected:Books'], ['e', self::BOOK]]);
        $this->api->method('getPublicationEventsByIds')->willThrowException(new MercuryUnavailableException('offline'));
        $this->logger->expects(self::once())->method('warning');
        self::assertSame('failed', $this->service()->page()['state']);
    }

    public function testFullDirectoryResolvesOnlyOneBoundedPage(): void
    {
        $tags = [['d', 'selected:Books']];
        for ($i = 0; $i < 101; ++$i) {
            $tags[] = ['e', sprintf('%064x', $i + 1)];
        }
        $this->source->setTags($tags);
        $this->api->expects(self::once())->method('getPublicationEventsByIds')
            ->with(array_map(static fn (int $i): string => sprintf('%064x', $i), range(51, 100)))->willReturn([]);
        $page = $this->service()->page(page: 2, eventId: self::SOURCE);
        self::assertCount(50, $page['entries']);
        self::assertTrue($page['hasPrevious']);
        self::assertTrue($page['hasNext']);
    }

    private function noMetadataRequests(): void
    {
        $this->api->expects(self::never())->method('getPublicationEventsByIds');
        $this->api->expects(self::never())->method('getPublicationsByAuthors');
    }

    private function service(?string $naddr = null): FeaturedBookshelf
    {
        return new FeaturedBookshelf(
            $naddr ?? $this->nip19->encodeAddr(self::AUTHOR, 'selected:Books', 30045),
            $this->nip19, $this->events,
            new EventPresentationFactory(new RecordIdentityService(), $this->nip19),
            $this->api, $this->authors, $this->hidden, $this->logger,
        );
    }

    private function book(string $id = self::BOOK, string $identifier = 'book', int $createdAt = 100): MercuryEvent
    {
        return new MercuryEvent($id, self::BOOK_AUTHOR, 30040, $createdAt, '', '', [
            ['d', $identifier], ['title', 'A book'], ['a', '30041:' . self::BOOK_AUTHOR . ':chapter'],
        ]);
    }
}
