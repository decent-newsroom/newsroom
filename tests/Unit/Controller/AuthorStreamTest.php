<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthorController;
use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\VanityNameService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

final class AuthorStreamTest extends TestCase
{
    /** @dataProvider viewers */
    public function testTheStreamCombinesExistingSourcesButNeverLoadsVisitorDrafts(bool $owner): void
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
            'isCached' => true,
            'isStale' => false,
            'data' => $section === 'overview' ? [
                'authorReadingLists' => [['pubkey' => $pubkey, 'slug' => 'reading', 'title' => 'Reading', 'createdAt' => 300]],
                'featuredInFollowPacks' => [['pubkey' => str_repeat('b', 64), 'dTag' => 'people', 'title' => 'People', 'createdAt' => 50]],
            ] : [],
        ]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findBy')
            ->with(['pubkey' => $pubkey, 'kind' => 9802], ['created_at' => 'DESC'], 50)->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($events);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $logger = new NullLogger();
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
        );
        self::assertSame(
            $owner ? ['reading_list', 'draft', 'article', 'follow_pack'] : ['reading_list', 'article', 'follow_pack'],
            array_column($data['rows'], 'type'),
        );
        self::assertFalse($data['editorialPending']);
        if (!$owner) {
            self::assertStringNotContainsString('secret', serialize($data['rows']));
            self::assertStringNotContainsString('Private draft', serialize($data['rows']));
        }
    }

    public static function viewers(): iterable
    {
        yield 'visitor' => [false];
        yield 'owner' => [true];
    }
}
