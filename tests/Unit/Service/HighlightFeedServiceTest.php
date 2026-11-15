<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Highlight;
use App\Repository\HighlightRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\HighlightFeedService;
use App\Service\Nostr\NostrLinkParser;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HighlightFeedServiceTest extends TestCase
{
    public function testHighlightCoordinateWinsOverUnrelatedArticleKindInRedisView(): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = '30041:' . $pubkey . ':chapter-one';
        $relay = 'wss://publication.example/CaseSensitive/';
        $viewStore = $this->createMock(RedisViewStore::class);
        $viewStore->method('fetchLatestHighlights')->willReturn([[
            'highlight' => [
                'eventId' => str_repeat('b', 64),
                'content' => 'A passage',
                'pubkey' => $pubkey,
                'refs' => [
                    'article_coordinate' => $coordinate,
                    'event_ref' => 'invalid',
                    'relay_hints' => [$relay],
                ],
            ],
            'article' => [
                'kind' => 30023,
                'pubkey' => $pubkey,
                'slug' => 'chapter-one',
                'title' => 'Unrelated article',
            ],
        ]]);
        $parser = new NostrLinkParser(new NullLogger());

        $service = new HighlightFeedService(
            $this->createMock(HighlightRepository::class),
            $this->createMock(RedisCacheService::class),
            $viewStore,
            $parser,
        );
        $feed = $service->loadLatestHighlights();

        self::assertSame($coordinate, $feed['highlights'][0]['article_ref']);
        self::assertNull($feed['highlights'][0]['event_ref']);
        $reference = new Bech32($feed['highlights'][0]['naddr']);
        self::assertSame(30041, $reference->data->kind);
        self::assertSame($pubkey, $reference->data->pubkey);
        self::assertSame('chapter-one', $reference->data->identifier);
        self::assertSame([$relay], $reference->data->relays);
        self::assertTrue($feed['from_redis_view']);
    }

    public function testDatabaseHighlightsRetainRelayPathIdentityInAddressPreview(): void
    {
        $pubkey = str_repeat('b', 64);
        $coordinate = '30023:' . $pubkey . ':article';
        $relay = 'wss://publication.example/CaseSensitive/';
        $highlight = (new Highlight())
            ->setEventId(str_repeat('c', 64))
            ->setArticleCoordinate($coordinate)
            ->setContent('A passage')
            ->setPubkey($pubkey)
            ->setCreatedAt(123)
            ->setRawEvent([
                'tags' => [
                    ['a', $coordinate, $relay],
                    ['E', str_repeat('d', 64)],
                    ['e', strtoupper(str_repeat('c', 64))],
                ],
            ]);
        $repository = $this->createMock(HighlightRepository::class);
        $repository->expects(self::once())->method('findLatestWithArticles')
            ->willReturn([['highlight' => $highlight, 'article' => null]]);
        $viewStore = $this->createMock(RedisViewStore::class);
        $viewStore->method('fetchLatestHighlights')->willReturn(null);

        $service = new HighlightFeedService(
            $repository,
            $this->createMock(RedisCacheService::class),
            $viewStore,
            new NostrLinkParser(new NullLogger()),
        );
        $feed = $service->loadLatestHighlights();
        $reference = new Bech32($feed['highlights'][0]['naddr']);

        self::assertSame([$relay], $feed['highlights'][0]['relayHints']);
        self::assertSame(str_repeat('c', 64), $feed['highlights'][0]['event_ref']);
        self::assertSame($coordinate, $feed['highlights'][0]['article_ref']);
        self::assertSame([$relay], $reference->data->relays);
        self::assertSame(30023, $reference->data->kind);
        self::assertSame($pubkey, $reference->data->pubkey);
        self::assertSame('article', $reference->data->identifier);
    }
}
