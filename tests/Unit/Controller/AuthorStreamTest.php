<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthorController;
use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\PublicationIndexStream;
use App\Service\Reader\ProfileFeaturedContent;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Reader\ArticleAccessService;
use App\Service\UserMuteListService;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\HighlightRepository;
use App\Service\Reader\HighlightSourceVisibility;
use App\Service\Nostr\NostrNip19Service;
use Symfony\Bundle\SecurityBundle\Security;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use App\Service\VanityNameService;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AuthorStreamTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    /** @dataProvider viewers */
    public function testTheStreamCombinesExistingSourcesButNeverLoadsVisitorDrafts(bool $owner, bool $cached, bool $stale): void
    {
        $pubkey = str_repeat('a', 64);
        $article = (new Article())->setPubkey($pubkey)->setSlug('public')->setTitle('Public essay')
            ->setKind(KindsEnum::LONGFORM)->setCreatedAt(new \DateTimeImmutable('@100'));
        $draft = (new Article())->setPubkey($pubkey)->setSlug('secret')->setTitle('Private draft')
            ->setKind(KindsEnum::LONGFORM_DRAFT)->setCreatedAt(new \DateTimeImmutable('@200'));
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::exactly($owner ? 2 : 1))->method('findByPubkey')
            ->willReturn([$article, $draft]);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->method('getMetadata')->willReturn(UserMetadata::createDefault($pubkey));
        $cache->expects(self::once())->method('getMediaEventsPaginated')->with($pubkey, 1, 200)
            ->willReturn(['events' => [], 'total' => 0, 'hasMore' => false]);
        $views = $this->createMock(RedisViewStore::class);
        $views->method('fetchProfileTabData')->willReturnCallback(static fn (string $key, string $section): array => [
            'isCached' => $section !== 'overview' || $cached,
            'isStale' => $section === 'overview' && $stale,
            'data' => $section === 'overview' && $cached ? [
                'authorMagazines' => [['pubkey' => $pubkey, 'slug' => 'book', 'title' => 'Stale cached book', 'createdAt' => 999]],
                'authorReadingLists' => [
                    ['pubkey' => $pubkey, 'slug' => 'reading', 'title' => 'Reading', 'createdAt' => 300],
                    ['pubkey' => $pubkey, 'slug' => 'private', 'title' => 'Stale private', 'createdAt' => 999],
                ],
                'featuredInFollowPacks' => [['pubkey' => str_repeat('b', 64), 'dTag' => 'people', 'title' => 'People', 'createdAt' => 50]],
            ] : [],
        ]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findBy')
            ->with(['pubkey' => $pubkey, 'kind' => 9802], ['created_at' => 'DESC'], 50)->willReturn([]);
        $pack = new Event();
        $pack->setId(str_repeat('d', 64));
        $pack->setPubkey(str_repeat('b', 64));
        $pack->setKind(39089);
        $pack->setCreatedAt(50);
        $pack->setTags([['d', 'people'], ['title', 'Signed people'], ['p', $pubkey]]);
        $events->expects($cached ? self::once() : self::never())->method('findReadingNookSubscriptionTargets')
            ->willReturn(['39089:' . str_repeat('b', 64) . ':people' => $pack]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($events);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($cached && !$stale ? self::never() : self::once())->method('dispatch')
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $logger = new NullLogger();
        $security = $this->createMock(Security::class);
        $keys = new NostrKeyService();
        $mutes = $this->createMock(UserMuteListService::class);
        $indexEvents = $this->createMock(EventRepository::class);
        $index = new Event();
        $index->setId(str_repeat('b', 64));
        $index->setPubkey($pubkey);
        $index->setKind(30040);
        $index->setCreatedAt(400);
        $index->setTags([['d', 'book'], ['title', 'Signed book']]);
        $private = new Event();
        $private->setId(str_repeat('c', 64));
        $private->setPubkey($pubkey);
        $private->setKind(30040);
        $private->setCreatedAt(500);
        $private->setTags([['d', 'private'], ['s', 'restricted'], ['title', 'Private index']]);
        $indexEvents->expects(self::once())->method('findBy')
            ->with(['kind' => 30040, 'pubkey' => $pubkey], ['created_at' => 'DESC', 'id' => 'ASC'], 200)
            ->willReturn([$private, $index]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn([]);
        $deleted = $this->createMock(DeletedEventRepository::class);
        $deleted->method('findSuppressedEventIds')->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturn([]);
        $access = new EventPreviewAccessPolicy(
            $authors, $deleted, $hidden,
            new RecordIdentityService(), $security, $mutes, $keys, $logger,
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class), $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        $controller = new AuthorController(
            $logger,
            new NostrLinkParser($logger),
            $this->createMock(VanityNameService::class),
            $this->createMock(CacheItemPoolInterface::class),
            $articles,
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
        $data = (new \ReflectionMethod($controller, 'getProfileStreamData'))->invoke(
            $controller, $pubkey, $owner, Request::create('/profile/stream'), $cache, $views,
            new RedisViewFactory($cache, $logger), $em, $bus,
            new PublicationIndexStream($indexEvents, $access, $security, $keys),
            new ProfileFeaturedContent($events, $access, $security, $keys),
            $this->makeHighlightVisibility($events, articles: $articles, security: $security, access: $access),
        );
        self::assertSame(
            ['magazine', ...($cached ? ['reading_list'] : []), ...($owner ? ['draft'] : []), 'article', ...($cached ? ['follow_pack'] : [])],
            array_column($data['rows'], 'type'),
        );
        self::assertSame('Signed book', $data['rows'][0]['item']['title']);
        self::assertSame(str_repeat('b', 64), $data['rows'][0]['item']['indexEventId']);
        self::assertStringNotContainsString('Stale', serialize($data['rows']));
        self::assertStringNotContainsString('Private index', serialize($data['rows']));
        self::assertSame(!$cached || $stale, $data['editorialPending']);
        self::assertSame($cached ? ['Signed people'] : [], array_column(array_column($data['featuredRows'], 'item'), 'title'));
        if (!$owner) {
            self::assertStringNotContainsString('secret', serialize($data['rows']));
            self::assertStringNotContainsString('Private draft', serialize($data['rows']));
        }
    }

    public static function viewers(): iterable
    {
        yield 'visitor' => [false, true, false];
        yield 'owner' => [true, true, false];
        yield 'visitor stale overview' => [false, true, true];
        yield 'owner stale overview' => [true, true, true];
        yield 'visitor cold overview' => [false, false, false];
        yield 'owner cold overview' => [true, false, false];
    }
}
