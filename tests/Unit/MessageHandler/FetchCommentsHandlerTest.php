<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\FetchCommentsMessage;
use App\MessageHandler\FetchCommentsHandler;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\CommentEventProjector;
use App\Service\Nostr\NostrClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class FetchCommentsHandlerTest extends TestCase
{
    public function testCacheHitStillRefreshesRelays(): void
    {
        $nostrClient = $this->createMock(NostrClient::class);
        $redisCache = $this->createMock(RedisCacheService::class);
        $eventRepository = $this->createMock(EventRepository::class);
        $projector = $this->createMock(CommentEventProjector::class);
        $hub = $this->createMock(HubInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $coordinate = '30023:authorpubkey:article-slug';
        $authorPubkey = 'authorpubkey';

        $cachedComment = (object) [
            'id' => str_repeat('1', 64),
            'kind' => 1111,
            'pubkey' => str_repeat('2', 64),
            'content' => 'Cached comment',
            'created_at' => 100,
            'tags' => [['A', $coordinate]],
            'sig' => str_repeat('3', 128),
        ];

        $freshComment = (object) [
            'id' => str_repeat('4', 64),
            'kind' => 1111,
            'pubkey' => str_repeat('5', 64),
            'content' => 'Fresh comment',
            'created_at' => 101,
            'tags' => [['A', $coordinate]],
            'sig' => str_repeat('6', 128),
        ];

        $redisCache->expects($this->once())
            ->method('getCommentsPayload')
            ->with($coordinate)
            ->willReturn([
                'comments' => [$cachedComment],
                'profiles' => [],
            ]);

        $eventRepository->expects($this->once())
            ->method('findCommentsByCoordinate')
            ->with($coordinate)
            ->willReturn([]);

        $eventRepository->expects($this->once())
            ->method('findLatestCommentTimestamp')
            ->with($coordinate)
            ->willReturn(99);

        $nostrClient->expects($this->exactly(2))
            ->method('getComments')
            ->willReturnCallback(function (string $ref, ?int $since, ?string $author) use ($coordinate, $authorPubkey, $freshComment): array {
                if ($ref === $coordinate) {
                    TestCase::assertSame(99, $since);
                    TestCase::assertSame($authorPubkey, $author);
                    return [$freshComment];
                }

                TestCase::assertSame($freshComment->id, $ref);
                TestCase::assertNull($since);
                TestCase::assertSame($authorPubkey, $author);
                return [];
            });

        $projector->expects($this->once())
            ->method('projectEvents')
            ->with([$freshComment])
            ->willReturn(0);

        $hub->expects($this->once())
            ->method('publish')
            ->with($this->isInstanceOf(Update::class));

        $handler = new FetchCommentsHandler(
            $nostrClient,
            $redisCache,
            $eventRepository,
            $projector,
            $hub,
            $logger
        );

        $handler(new FetchCommentsMessage($coordinate, $authorPubkey));
    }

    public function testHydratesOneHopRepliesForFetchedComments(): void
    {
        $nostrClient = $this->createMock(NostrClient::class);
        $redisCache = $this->createMock(RedisCacheService::class);
        $eventRepository = $this->createMock(EventRepository::class);
        $projector = $this->createMock(CommentEventProjector::class);
        $hub = $this->createMock(HubInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $coordinate = '30023:authorpubkey:article-slug';
        $authorPubkey = 'authorpubkey';

        $topLevel = (object) [
            'id' => str_repeat('a', 64),
            'kind' => 1111,
            'pubkey' => str_repeat('b', 64),
            'created_at' => 100,
            'tags' => [['A', $coordinate]],
            'content' => 'Top-level',
            'sig' => str_repeat('c', 128),
        ];

        $reply = (object) [
            'id' => str_repeat('d', 64),
            'kind' => 1111,
            'pubkey' => str_repeat('e', 64),
            'created_at' => 101,
            'tags' => [['e', $topLevel->id]],
            'content' => 'Reply',
            'sig' => str_repeat('f', 128),
        ];

        $redisCache->expects($this->once())
            ->method('getCommentsPayload')
            ->with($coordinate)
            ->willReturn(null);

        $eventRepository->expects($this->once())
            ->method('findCommentsByCoordinate')
            ->with($coordinate)
            ->willReturn([]);

        $eventRepository->expects($this->once())
            ->method('findLatestCommentTimestamp')
            ->with($coordinate)
            ->willReturn(null);

        $nostrClient->expects($this->exactly(2))
            ->method('getComments')
            ->willReturnCallback(function (string $ref, ?int $since, ?string $author) use ($coordinate, $authorPubkey, $topLevel, $reply): array {
                if ($ref === $coordinate) {
                    TestCase::assertNull($since);
                    TestCase::assertSame($authorPubkey, $author);
                    return [$topLevel];
                }

                TestCase::assertSame($topLevel->id, $ref);
                TestCase::assertNull($since);
                TestCase::assertSame($authorPubkey, $author);
                return [$reply];
            });

        $projector->expects($this->once())
            ->method('projectEvents')
            ->with($this->callback(function (array $events) use ($topLevel, $reply): bool {
                $ids = array_map(static fn(object $e): string => $e->id, $events);
                sort($ids);
                $expected = [$reply->id, $topLevel->id];
                sort($expected);

                return $ids === $expected;
            }))
            ->willReturn(0);

        $handler = new FetchCommentsHandler(
            $nostrClient,
            $redisCache,
            $eventRepository,
            $projector,
            $hub,
            $logger
        );

        $handler(new FetchCommentsMessage($coordinate, $authorPubkey));
    }
}

