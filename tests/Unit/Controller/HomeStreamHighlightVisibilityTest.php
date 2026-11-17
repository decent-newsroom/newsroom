<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\HomeFeedController;
use App\Entity\Event;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\SubscriptionChangeRepository;
use App\Repository\UpdateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\FollowPackService;
use App\Service\HighlightFeedService;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\UserProfileService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\ContentStreamBuilder;
use App\Service\Reader\FeaturedWritersFeed;
use App\Service\Reader\FeaturedWritersRecommendations;
use App\Service\Reader\PublicationIndexStream;
use App\Service\Search\ArticleSearchFactory;
use App\Service\Search\ContentSearchService;
use App\Service\Update\SubscriptionFeed;
use App\Service\Update\SubscriptionSource;
use App\Service\Update\UpdateMatcher;
use App\Service\UserMuteListService;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

final class HomeStreamHighlightVisibilityTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testGuestStreamFiltersMissingSourcesBeforePaginationAndCounting(): void
    {
        $items = $highlights = $sources = [];
        for ($number = 1; $number <= 50; ++$number) {
            $id = self::id($number);
            $sourceId = self::id(100 + $number);
            $items[] = [
                'id' => $id,
                'pubkey' => self::AUTHOR,
                'content' => 'passage ' . $number,
                'created_at' => 1000 - $number,
            ];
            $highlights[$id] = $this->event($id, 9802, [['e', $sourceId]]);
            if ($number > 25) {
                $sources[$sourceId] = $this->event($sourceId, 1, []);
            }
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByIds')->willReturn($highlights);
        $events->expects(self::once())->method('findHighlightSourceTargets')
            ->willReturn(['eventIds' => $sources, 'coordinates' => []]);
        $feed = $this->createMock(HighlightFeedService::class);
        $feed->expects(self::once())->method('loadLatestHighlights')->with(200)->willReturn(['highlights' => $items]);
        $views = $this->createMock(RedisViewStore::class);
        $views->method('fetchLatestArticles')->willReturn([]);
        $cache = $this->createMock(RedisCacheService::class);
        $articles = $this->createMock(ArticleRepository::class);
        $security = $this->createMock(Security::class);
        $access = $this->makeHighlightPreviewAccess($security);
        $updates = $this->createMock(UpdateRepository::class);
        $subscriptions = $this->createMock(UpdateSubscriptionRepository::class);
        $nip19 = new NostrNip19Service();
        $subscriptionFeed = new SubscriptionFeed(
            $updates, $events, $articles, $this->createMock(SubscriptionChangeRepository::class),
            new SubscriptionSource($nip19, $events), $access, $this->createMock(UpdateMatcher::class), $nip19,
        );
        $controller = new class($subscriptionFeed) extends HomeFeedController {
            public array $parameters = [];
            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->parameters = $parameters;
                return $response ?? new Response();
            }
            protected function getUser(): ?UserInterface
            {
                return null;
            }
        };
        $featured = new FeaturedWritersFeed($this->createMock(UserEntityRepository::class), $articles, $cache);
        $index = new PublicationIndexStream(
            $this->createMock(EventRepository::class), $access, $security, new NostrKeyService(),
        );

        $response = $controller->contentStream(
            Request::create('/home/stream?page=2'), $cache, $views,
            $this->createMock(LatestArticlesExclusionPolicy::class), $this->createMock(ArticleSearchFactory::class),
            $articles, $events, $this->createMock(NostrClient::class), $this->createMock(UserProfileService::class),
            $this->createMock(UserMuteListService::class), $this->createMock(FollowPackService::class),
            $updates, $subscriptions, new NullLogger(), $this->createMock(ContentSearchService::class), $feed,
            $featured, $this->createMock(FeaturedWritersRecommendations::class), new ContentStreamBuilder(),
            $this->createMock(ContentAuthorAccessPolicy::class), $index,
            $this->makeHighlightVisibility($events, security: $security),
        );

        self::assertSame(25, $controller->parameters['pager']->getNbResults());
        self::assertSame(2, $controller->parameters['pager']->getNbPages());
        self::assertSame(
            array_map(self::id(...), range(46, 50)),
            array_map(static fn (array $row): string => $row['item']['id'], $controller->parameters['rows']),
        );
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
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
