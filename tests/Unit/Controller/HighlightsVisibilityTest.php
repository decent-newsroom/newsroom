<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\HighlightsController;
use App\Entity\Event;
use App\Entity\Highlight;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\HighlightRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\HighlightFeedService;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\HighlightSourceVisibility;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class HighlightsVisibilityTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @dataProvider viewersAndSources */
    public function testLatestFeedFiltersBeforeCountingWithoutChangingSharedResults(bool $cached, bool $signedIn): void
    {
        $local = '30040:' . self::AUTHOR . ':local';
        $missing = '30023:' . self::AUTHOR . ':missing';
        $missingExact = self::id(99);
        $rows = [];
        $snapshot = [];
        $stored = [];
        foreach ([
            1 => [$local, [['a', $local]]],
            2 => [$missing, [['a', $missing]]],
            3 => [$local, [['a', $local], ['e', $missingExact]]],
        ] as $number => [$coordinate, $tags]) {
            $id = self::id($number);
            $highlight = (new Highlight())->setEventId($id)->setPubkey(self::AUTHOR)
                ->setContent('passage ' . $number)->setArticleCoordinate($coordinate)
                ->setCreatedAt(123)->setRawEvent(['tags' => $tags]);
            $rows[] = ['highlight' => $highlight, 'article' => null];
            $snapshot[] = [
                'highlight' => [
                    'eventId' => $id,
                    'pubkey' => self::AUTHOR,
                    'content' => 'passage ' . $number,
                    // Simulate an older Redis snapshot that omitted the exact reference.
                    'refs' => ['article_coordinate' => $coordinate],
                ],
                // This may be a synthesized placeholder, not a local Article.
                'article' => ['id' => '', 'kind' => 30023, 'pubkey' => self::AUTHOR, 'slug' => 'missing', 'title' => 'Cached'],
            ];
            $stored[$id] = $this->event($id, 9802, $tags);
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects($signedIn ? self::never() : self::once())->method('findByIds')->willReturn($stored);
        $events->expects($signedIn ? self::never() : self::once())->method('findHighlightSourceTargets')
            ->willReturn([
                'eventIds' => [],
                'coordinates' => [$local => $this->event(self::id(50), 30040, [['d', 'local']])],
            ]);
        $highlights = $this->createMock(HighlightRepository::class);
        $highlights->expects($cached ? self::never() : self::once())->method('findLatestWithArticles')->willReturn($rows);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findByCoordinates')->willReturn([]);
        $viewStore = $this->createMock(RedisViewStore::class);
        $viewStore->method('fetchLatestHighlights')->willReturn($cached ? $snapshot : null);
        $security = $this->createMock(Security::class);
        if ($signedIn) {
            $security->method('getUser')->willReturn($this->createMock(UserInterface::class));
        }
        $logger = new NullLogger();
        $feed = new HighlightFeedService(
            $highlights, $this->createMock(RedisCacheService::class), $viewStore, new NostrLinkParser($logger),
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($cached ? self::never() : self::once())->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $controller = new class($logger, $feed, $bus) extends HighlightsController {
            public array $parameters = [];
            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->parameters = $parameters;
                return $response ?? new Response();
            }
        };
        $visibility = $this->makeHighlightVisibility($events, $highlights, $articles, $security, logger: $logger);

        $response = $controller->index($visibility);

        self::assertSame($signedIn ? [self::id(1), self::id(2), self::id(3)] : [self::id(1)],
            array_column($controller->parameters['highlights'], 'id'));
        self::assertSame($signedIn ? 3 : 1, $controller->parameters['total']);
        self::assertSame($cached, $controller->parameters['from_redis_view']);
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function viewersAndSources(): iterable
    {
        yield 'guest Redis view' => [true, false];
        yield 'guest database fallback' => [false, false];
        yield 'signed in Redis view' => [true, true];
        yield 'signed in database fallback' => [false, true];
    }

    private function event(string $id, int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey(self::AUTHOR);
        $event->setKind($kind);
        $event->setTags($tags);
        return $event;
    }

    private static function id(int $number): string
    {
        return str_pad(dechex($number), 64, '0', STR_PAD_LEFT);
    }
}
