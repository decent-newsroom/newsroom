<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Message\RefreshReaderInteractionsMessage;
use Doctrine\DBAL\Connection;
use App\Unfold\InteractionHydrator;
use App\Unfold\InteractionReaderAdapter;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class InteractionReaderAdapterTest extends TestCase
{
    public function testRefreshQueuesFocusedMessageOnCacheMiss(): void
    {
        $target = $this->target();
        $hydrator = $this->hydrator();
        $bus = $this->createMock(MessageBusInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $logger = new NullLogger();

        $cache->expects(self::once())->method('getItem')->with(self::isType('string'))->willReturn($item);
        $item->expects(self::once())->method('isHit')->willReturn(false);
        $item->expects(self::once())->method('set')->with(self::isType('int'))->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(15)->willReturnSelf();
        $cache->expects(self::once())->method('save')->with($item)->willReturn(true);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static function (object $message): bool {
            return $message instanceof RefreshReaderInteractionsMessage
                && $message->coordinate === '30023:' . str_repeat('a', 64) . ':story'
                && $message->publicationCoordinate === '30040:' . str_repeat('b', 64) . ':magazine';
        }))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $adapter = new InteractionReaderAdapter($hydrator, $bus, $cache, $logger, $this->connection());
        $adapter->refresh($target);
    }

    public function testRefreshIsDebouncedWhenCacheHit(): void
    {
        $target = $this->target();
        $hydrator = $this->hydrator();
        $bus = $this->createMock(MessageBusInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $logger = new NullLogger();

        $cache->expects(self::once())->method('getItem')->willReturn($item);
        $item->expects(self::once())->method('isHit')->willReturn(true);
        $cache->expects(self::never())->method('save');
        $bus->expects(self::never())->method('dispatch');

        $adapter = new InteractionReaderAdapter($hydrator, $bus, $cache, $logger, $this->connection());
        $adapter->refresh($target);
    }

    public function testDispatchFailureClearsReservationAndAllowsRetry(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $hit = false;
        $item->method('isHit')->willReturnCallback(static function () use (&$hit): bool { return $hit; });
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();
        $cache->method('getItem')->willReturn($item);
        $cache->expects(self::exactly(2))->method('save')->willReturnCallback(static function () use (&$hit): bool {
            $hit = true;
            return true;
        });
        $cache->expects(self::once())->method('deleteItem')->willReturnCallback(static function () use (&$hit): bool {
            $hit = false;
            return true;
        });
        $attempt = 0;
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function (object $message) use (&$attempt): Envelope {
            if (++$attempt === 1) {
                throw new \RuntimeException('Queue unavailable');
            }
            return new Envelope($message);
        });
        $adapter = new InteractionReaderAdapter($this->hydrator(), $bus, $cache, new NullLogger(), $this->connection());
        try {
            $adapter->refresh($this->target());
            self::fail('Queue failure must reach the controller');
        } catch (\RuntimeException $e) {
            self::assertSame('unfold_interactions.unavailable', $e->getMessage());
        }
        self::assertFalse($hit);
        $adapter->refresh($this->target());
        self::assertTrue($hit);
    }

    public function testFailedCacheReservationDoesNotDispatch(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        (new InteractionReaderAdapter($this->hydrator(), $bus, $cache, new NullLogger(), $this->connection()))
            ->refresh($this->target());
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(true);
        return $connection;
    }

    private function target(): InteractionTarget
    {
        return new InteractionTarget(
            '30040:' . str_repeat('b', 64) . ':magazine',
            new PostData(
                slug: 'story',
                title: 'Story title',
                summary: 'Story summary',
                content: 'Body',
                image: null,
                publishedAt: 2000,
                pubkey: str_repeat('a', 64),
                coordinate: '30023:' . str_repeat('a', 64) . ':story',
                kind: 30023,
                tags: [['d', 'story']],
                eventId: str_repeat('1', 64),
            ),
            null,
            'wss://relay.example',
        );
    }

    private function hydrator(): InteractionHydrator
    {
        $articleRepo = $this->createMock(ArticleRepository::class);
        $articleRepo->method('findByCoordinates')->willReturn([]);
        $articleRepo->method('findBy')->willReturn([]);

        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->method('findByCoordinates')->willReturn([]);
        $eventRepo->method('findBy')->willReturn([]);

        $comments = $this->createMock(CommentProviderInterface::class);
        $comments->method('findByCoordinate')->willReturn([]);

        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->method('findDescendants')->willReturn([]);

        return new InteractionHydrator(
            $comments,
            $articleRepo,
            $eventRepo,
            $tree,
            $this->createMock(\App\Service\Nostr\RelayRegistry::class),
            new NullLogger(),
        );
    }
}
