<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthorController;
use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Entity\User;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\ProfileFeaturedContent;
use App\Service\Reader\PublicationIndexStream;
use App\Service\VanityNameService;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ProfileHighlightVisibilityTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testProfileStreamFiltersMissingSourcesBeforePaginationAndCounting(): void
    {
        $highlightEvents = $storedHighlights = $storedSources = [];
        for ($number = 1; $number <= 50; ++$number) {
            $id = self::id($number);
            $sourceId = self::id(100 + $number);
            $highlight = $this->event($id, 9802, [['e', $sourceId]]);
            $highlight->setContent('passage ' . $number);
            $highlight->setCreatedAt(1000 - $number);
            $highlightEvents[] = $highlight;
            $storedHighlights[$id] = $highlight;
            if ($number > 25) {
                $storedSources[$sourceId] = $this->event($sourceId, 1, []);
            }
        }

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findBy')->willReturn($highlightEvents);
        $events->expects(self::once())->method('findByIds')->willReturn($storedHighlights);
        $events->expects(self::once())->method('findHighlightSourceTargets')
            ->willReturn(['eventIds' => $storedSources, 'coordinates' => []]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(Event::class)->willReturn($events);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->method('getMediaEventsPaginated')->willReturn(['events' => [], 'hasMore' => false, 'total' => 0]);
        $views = $this->createMock(RedisViewStore::class);
        $views->method('fetchProfileTabData')->willReturn(['isCached' => true, 'isStale' => false, 'data' => []]);
        $security = $this->createMock(Security::class);
        $access = $this->makeHighlightPreviewAccess($security);
        $index = new PublicationIndexStream(
            $this->createMock(EventRepository::class), $access, $security, new NostrKeyService(),
        );
        $featured = new ProfileFeaturedContent(
            $this->createMock(EventRepository::class), $access, $security, new NostrKeyService(),
        );
        $controller = $this->controller();

        $data = (new \ReflectionMethod(AuthorController::class, 'getProfileStreamData'))->invoke(
            $controller, self::AUTHOR, false, Request::create('/profile/stream?page=2'), $cache, $views,
            $this->createMock(RedisViewFactory::class), $manager, $this->createMock(MessageBusInterface::class),
            $index, $featured, $this->makeHighlightVisibility($events, security: $security),
        );

        self::assertSame(25, $data['pager']->getNbResults());
        self::assertSame(2, $data['pager']->getNbPages());
        self::assertSame(
            array_map(self::id(...), range(46, 50)),
            array_map(static fn (array $row): string => $row['item']->id, $data['rows']),
        );
    }

    /** @dataProvider viewers */
    public function testCachedProfileHighlightsUsePersistedReferencesOnlyForGuests(bool $signedIn): void
    {
        $local = '30040:' . self::AUTHOR . ':local';
        $missing = '30040:' . self::AUTHOR . ':missing';
        $stored = $cached = [];
        foreach ([
            1 => [['a', $local]],
            2 => [['a', $missing]],
            3 => [['a', $local], ['e', str_repeat('f', 64)]],
        ] as $number => $tags) {
            $id = self::id($number);
            $stored[$id] = $this->event($id, 9802, $tags);
            $cached[] = ['id' => $id, 'article_ref' => $local, 'content' => 'passage ' . $number];
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects($signedIn ? self::never() : self::once())->method('findByIds')->willReturn($stored);
        $events->expects($signedIn ? self::never() : self::once())->method('findHighlightSourceTargets')
            ->willReturn([
                'eventIds' => [],
                'coordinates' => [$local => $this->event(self::id(100), 30040, [['d', 'local']])],
            ]);
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getRepository')->with(User::class)->willReturn($this->createMock(UserEntityRepository::class));
        $views = $this->createMock(RedisViewStore::class);
        $views->expects(self::once())->method('fetchProfileTabData')
            ->with(self::AUTHOR, 'highlights')
            ->willReturn(['isCached' => true, 'isStale' => false, 'data' => ['highlights' => $cached]]);
        $views->expects(self::never())->method('storeProfileTabData');
        $security = $this->createMock(Security::class);
        $viewer = null;
        if ($signedIn) {
            $viewer = $this->createMock(UserInterface::class);
            $viewer->method('getUserIdentifier')->willReturn('another-user');
            $security->method('getUser')->willReturn($viewer);
        }
        $controller = $this->controller();
        $controller->viewer = $viewer;
        $cache = $this->createMock(RedisCacheService::class);
        $cache->method('getMetadata')->willReturn(UserMetadata::createDefault(self::AUTHOR));
        $access = $this->makeHighlightPreviewAccess($security);
        $index = new PublicationIndexStream(
            $this->createMock(EventRepository::class), $access, $security, new NostrKeyService(),
        );
        $featured = new ProfileFeaturedContent(
            $this->createMock(EventRepository::class), $access, $security, new NostrKeyService(),
        );
        $request = new Request();
        $request->headers->set('Turbo-Frame', 'profile-tab-content');

        $response = $controller->profileTab(
            'highlights', $request, $cache, $this->createMock(MessageBusInterface::class), $views,
            $this->createMock(RedisViewFactory::class), $manager, $index, $featured,
            $this->makeHighlightVisibility($events, security: $security),
            (new NostrNip19Service())->encodeNpub(self::AUTHOR),
        );

        self::assertSame(
            array_map(self::id(...), $signedIn ? [1, 2, 3] : [1]),
            array_map(static fn (object $item): string => $item->id, $controller->parameters['highlights']),
        );
        if (!$signedIn) {
            self::assertNotNull($controller->parameters['highlights'][0]->naddr);
        }
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function viewers(): iterable
    {
        yield 'anonymous' => [false];
        yield 'signed in' => [true];
    }

    private function controller(): AuthorController
    {
        $logger = new NullLogger();
        return new class(
            $logger, new NostrLinkParser($logger), $this->createMock(VanityNameService::class),
            $this->createMock(CacheItemPoolInterface::class), $this->createMock(ArticleRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        ) extends AuthorController {
            public array $parameters = [];
            public ?UserInterface $viewer = null;
            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->parameters = $parameters;
                return $response ?? new Response();
            }
            protected function getUser(): ?UserInterface
            {
                return $this->viewer;
            }
            protected function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
            {
                return '';
            }
        };
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
