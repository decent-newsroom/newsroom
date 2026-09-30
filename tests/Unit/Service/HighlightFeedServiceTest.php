<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\HighlightRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\HighlightFeedService;
use App\Service\Nostr\NostrLinkParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class HighlightFeedServiceTest extends TestCase
{
    public function testHighlightCoordinateWinsOverUnrelatedArticleKindInRedisView(): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = '30041:' . $pubkey . ':chapter-one';
        $viewStore = $this->createMock(RedisViewStore::class);
        $viewStore->method('fetchLatestHighlights')->willReturn([[
            'highlight' => [
                'eventId' => str_repeat('b', 64),
                'content' => 'A passage',
                'pubkey' => $pubkey,
                'refs' => ['article_coordinate' => $coordinate],
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
        self::assertTrue($feed['from_redis_view']);
    }
}
