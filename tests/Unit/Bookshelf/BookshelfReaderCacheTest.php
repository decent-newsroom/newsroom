<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfReaderContents;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class BookshelfReaderCacheTest extends TestCase
{
    public function testRemoteRootAndOutlineAreReusedAcrossReaderInstances(): void
    {
        $cache = new ArrayAdapter();
        $root = $this->event('b', 30040, 'book', [['a', '30041:' . str_repeat('a', 64) . ':chapter']]);
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::once())->method('getEvent')->with($root->id)->willReturn($root);
        $first = $this->reader($cache, $api);
        $source = $first->source($root->id);
        self::assertNotNull($source);
        $outline = $first->outline($source);

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByIds');
        $second = $this->reader($cache, $api, events: $events);
        self::assertEquals($source, $second->source($root->id));
        self::assertSame($outline, $second->outline($source));
        self::assertCount(1, $outline['entries']);
    }

    public function testCachedCoordinateAndExactRevisionStillRecheckVisibility(): void
    {
        $cache = new ArrayAdapter();
        $chapter = $this->event('c', 30041, 'chapter', [], 'Cached chapter');
        $pubkey = str_repeat('a', 64);
        $root = (object) $this->event('b', 30040, 'book', [
            ['a', "30041:$pubkey:chapter"],
            ['a', "30041:$pubkey:chapter", '', $chapter->id],
        ])->toArray();
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::once())->method('getChaptersByAuthors')->with([$pubkey], 1, ['chapter'])->willReturn([$chapter]);
        $api->expects(self::never())->method('getEventsByIds');
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $blocked = false;
        $hidden->method('findHiddenCoordinates')->willReturnCallback(
            static function (array $coordinates) use (&$blocked): array {
                return $blocked ? $coordinates : [];
            },
        );
        $first = $this->reader($cache, $api, hidden: $hidden);
        self::assertSame('Cached chapter', $first->chapter($root, 1)['content']);

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findBookshelfReaderTargets')
            ->with(["30041:$pubkey:chapter"], [])
            ->willReturn(['coordinates' => [], 'eventIds' => []]);
        $second = $this->reader($cache, $api, events: $events, hidden: $hidden);
        self::assertSame($chapter->id, $second->chapter($root, 1)['id']);
        self::assertSame($chapter->id, $second->chapter($root, 2)['id']);
        $blocked = true;
        self::assertNull($second->chapter($root, 1));
        self::assertNull($second->chapter($root, 2));
    }

    public function testNewLocalRevisionWinsOverCachedCoordinate(): void
    {
        $cache = new ArrayAdapter();
        $chapter = $this->event('c', 30041, 'chapter', [], 'Old');
        $coordinate = '30041:' . str_repeat('a', 64) . ':chapter';
        $root = (object) $this->event('b', 30040, 'book', [['a', $coordinate]])->toArray();
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::once())->method('getChaptersByAuthors')->willReturn([$chapter]);
        self::assertSame('Old', $this->reader($cache, $api)->chapter($root, 1)['content']);

        $new = new Event();
        $new->setId(str_repeat('d', 64));
        $new->setPubkey(str_repeat('a', 64));
        $new->setKind(30041);
        $new->setCreatedAt(2);
        $new->setTags([['d', 'chapter']]);
        $new->setContent('New');
        $events = $this->createStub(EventRepository::class);
        $events->method('findBookshelfReaderTargets')->willReturn(['coordinates' => [$coordinate => $new], 'eventIds' => []]);
        self::assertSame('New', $this->reader($cache, $api, events: $events)->chapter($root, 1)['content']);
    }

    public function testCachedExactSourceStillHonorsNewHiddenCoordinate(): void
    {
        $cache = new ArrayAdapter();
        $root = $this->event('b', 30040, 'book');
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::once())->method('getEvent')->willReturn($root);
        $warm = $this->reader($cache, $api);
        $source = $warm->source($root->id);
        self::assertNotNull($warm->indexAtPath($source, []));

        $hidden = $this->createStub(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->with(['30040:' . $root->pubkey . ':book'])
            ->willReturn(['30040:' . $root->pubkey . ':book']);
        $reader = $this->reader($cache, $api, hidden: $hidden);
        self::assertNotNull($reader->source($root->id));
        self::assertNull($reader->indexAtPath($reader->source($root->id), []));
    }

    public function testMissingChaptersRemainRetryableInsteadOfCachingUnavailableResults(): void
    {
        $cache = new ArrayAdapter();
        $chapter = $this->event('c', 30041, 'chapter', [], 'Arrived later');
        $coordinate = '30041:' . str_repeat('a', 64) . ':chapter';
        $root = (object) $this->event('b', 30040, 'book', [['a', $coordinate]])->toArray();
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::exactly(2))->method('getChaptersByAuthors')->willReturnOnConsecutiveCalls([], [$chapter]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::once())->method('getLocalEvents')->willReturn([]);
        $events = $this->createStub(EventRepository::class);
        $events->method('findBookshelfReaderTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        $reader = new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $nostr, $events,
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class), $this->createStub(LoggerInterface::class), $api, $cache,
        );
        self::assertNull($reader->chapter($root, 1));
        self::assertSame('Arrived later', $reader->chapter($root, 1)['content']);
    }

    public function testRenderedBodyIsSharedByExactEventAndContentNotOccurrence(): void
    {
        $cache = new ArrayAdapter();
        $api = $this->createStub(MercuryApiClientInterface::class);
        $id = str_repeat('c', 64);
        $calls = 0;
        $render = static function () use (&$calls): string {
            ++$calls;
            return '<p>Body</p>';
        };
        self::assertSame('<p>Body</p>', $this->reader($cache, $api)->renderedBody($id, 'Body', $render));
        self::assertSame('<p>Body</p>', $this->reader($cache, $api)->renderedBody($id, 'Body', $render));
        self::assertSame(1, $calls);
        $this->reader($cache, $api)->renderedBody(str_repeat('d', 64), 'Body', $render);
        $this->reader($cache, $api)->renderedBody($id, 'Different', $render);
        self::assertSame(3, $calls);
    }

    public function testConversionFailuresAreNotCached(): void
    {
        $reader = $this->reader(new ArrayAdapter(), $this->createStub(MercuryApiClientInterface::class));
        try {
            $reader->renderedBody(str_repeat('c', 64), 'Body', static fn (): string => throw new \RuntimeException('failed'));
            self::fail('Conversion error was swallowed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('failed', $exception->getMessage());
        }
        self::assertSame('Recovered', $reader->renderedBody(str_repeat('c', 64), 'Body', static fn (): string => 'Recovered'));
    }

    private function reader(
        ArrayAdapter $cache,
        MercuryApiClientInterface $api,
        ?EventRepository $events = null,
        ?HiddenCoordinateRepository $hidden = null,
    ): BookshelfReaderContents {
        if ($events === null) {
            $events = $this->createStub(EventRepository::class);
            $events->method('findByIds')->willReturn([]);
            $events->method('findBookshelfReaderTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        }
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByIds');
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $nostr->expects(self::never())->method('getLocalEvents');

        return new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $hidden ?? $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $api, $cache,
        );
    }

    /** @param list<list<string>> $tags */
    private function event(string $id, int $kind, string $identifier, array $tags = [], string $content = ''): MercuryEvent
    {
        return new MercuryEvent(str_repeat($id, 64), str_repeat('a', 64), $kind, 1, $content, '', [['d', $identifier], ...$tags]);
    }
}
