<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\Event;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrKeyService;
use App\Service\ReadingListContentPreviewProvider;
use PHPUnit\Framework\TestCase;

final class ReadingListContentPreviewProviderTest extends TestCase
{
    private const PUBKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testArticleOnlyPreviewPreservesOriginalKeysAndSignedIdentifier(): void
    {
        $identifier = '  My:Article  ';
        $coordinate = '30023:' . self::PUBKEY . ':' . $identifier;
        $uppercase = '30023:' . strtoupper(self::PUBKEY) . ':' . $identifier;
        $article = $this->article(30023, $identifier, 'Projected article');
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findByCoordinates')
            ->with([['kind' => 30023, 'pubkey' => self::PUBKEY, 'slug' => $identifier]])
            ->willReturn([$coordinate => $article]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByCoordinates');
        $events->expects(self::never())->method('findByNaddr');
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::once())->method('getMultipleMetadata')->with([self::PUBKEY])
            ->willReturn([self::PUBKEY => new UserMetadata(name: 'Name', displayName: 'Display name')]);

        self::assertSame([
            $uppercase => ['title' => 'Projected article', 'author' => 'Display name'],
            $coordinate => ['title' => 'Projected article', 'author' => 'Display name'],
        ], $this->provider($articles, $events, $metadata)->findByCoordinates([$uppercase, $coordinate, $uppercase]));
    }

    public function testEventOnlyKindsAndNullableTitlesResolveInOneBatch(): void
    {
        $map = [];
        foreach ([30041, 30818, 30817] as $kind) {
            $coordinate = $kind . ':' . self::PUBKEY . ': signed:id ';
            $map[$coordinate] = $this->event($kind, ' signed:id ', [['title', $kind === 30817 ? 123 : 'Title ' . $kind]]);
        }
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findByCoordinates')->willReturn([]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByCoordinates')->with(array_keys($map))->willReturn($map);
        $events->expects(self::never())->method('findByNaddr');
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::once())->method('getMultipleMetadata')->with([self::PUBKEY])
            ->willReturn([self::PUBKEY => new UserMetadata(name: 'Author')]);

        $result = $this->provider($articles, $events, $metadata)->findByCoordinates(array_keys($map));
        self::assertCount(3, $result);
        self::assertSame(['title' => 'Title 30041', 'author' => 'Author'], $result[array_keys($map)[0]]);
        self::assertSame(['title' => 'Title 30818', 'author' => 'Author'], $result[array_keys($map)[1]]);
        self::assertSame(['title' => null, 'author' => 'Author'], $result[array_keys($map)[2]]);
    }

    public function testLegacyEventFallbackAndShortenedNpub(): void
    {
        $coordinate = '30818:' . self::PUBKEY . ':Legacy:Page ';
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findByCoordinates')->willReturn([]);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByCoordinates')->willReturn([]);
        $events->expects(self::once())->method('findByNaddr')->with(30818, self::PUBKEY, 'Legacy:Page ')
            ->willReturn($this->event(30818, 'Legacy:Page ', [['title', 'Legacy']]));
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->method('getMultipleMetadata')->willReturn([]);
        $npub = (new NostrKeyService())->convertPublicKeyToBech32(self::PUBKEY);

        self::assertSame([
            $coordinate => ['title' => 'Legacy', 'author' => substr($npub, 0, 8) . '...' . substr($npub, -4)],
        ], $this->provider($articles, $events, $metadata)->findByCoordinates([$coordinate]));
    }

    public function testKindAndAuthorArePartOfTheLookupIdentity(): void
    {
        $coordinate = '30023:' . self::PUBKEY . ':same-slug';
        $otherKind = '30041:' . self::PUBKEY . ':same-slug';
        $otherAuthor = '30023:' . str_repeat('b', 64) . ':same-slug';
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findByCoordinates')->with([
            ['kind' => 30023, 'pubkey' => self::PUBKEY, 'slug' => 'same-slug'],
            ['kind' => 30041, 'pubkey' => self::PUBKEY, 'slug' => 'same-slug'],
            ['kind' => 30023, 'pubkey' => str_repeat('b', 64), 'slug' => 'same-slug'],
        ])->willReturn([$coordinate => $this->article(30023, 'same-slug', 'Exact match')]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByCoordinates')->with([$otherKind, $otherAuthor])->willReturn([]);
        $events->expects(self::exactly(2))->method('findByNaddr')->willReturn(null);
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->method('getMultipleMetadata')->willReturn([self::PUBKEY => new UserMetadata(name: 'Author')]);

        self::assertSame([
            $coordinate => ['title' => 'Exact match', 'author' => 'Author'],
        ], $this->provider($articles, $events, $metadata)->findByCoordinates([$coordinate, $otherKind, $otherAuthor]));
    }

    public function testScopedArticleDoesNotFallThroughAndScopedEventsStayExcluded(): void
    {
        $articleCoordinate = '30023:' . self::PUBKEY . ':restricted';
        $eventCoordinate = '30818:' . self::PUBKEY . ':restricted';
        $article = $this->article(30023, 'restricted', 'Private');
        $article->setRaw(['tags' => [['s', 'private']]]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findByCoordinates')->willReturn([$articleCoordinate => $article]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByCoordinates')->with([$eventCoordinate])
            ->willReturn([$eventCoordinate => $this->event(30818, 'restricted', [['s', 'private'], ['title', 'Private']])]);
        $events->expects(self::never())->method('findByNaddr');
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMultipleMetadata');

        self::assertSame([], $this->provider($articles, $events, $metadata)
            ->findByCoordinates([$articleCoordinate, $eventCoordinate]));
    }

    public function testInvalidAndUnavailableReferencesAreAbsent(): void
    {
        $coordinate = '30023:' . self::PUBKEY . ':not-local';
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findByCoordinates')
            ->with([['kind' => 30023, 'pubkey' => self::PUBKEY, 'slug' => 'not-local']])->willReturn([]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByCoordinates')->with([$coordinate])->willReturn([]);
        $events->expects(self::once())->method('findByNaddr')->with(30023, self::PUBKEY, 'not-local')->willReturn(null);
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMultipleMetadata');

        self::assertSame([], $this->provider($articles, $events, $metadata)->findByCoordinates([
            '', 'invalid', '30023:not-a-key:slug', '-1:' . self::PUBKEY . ':slug',
            '65536:' . self::PUBKEY . ':slug', $coordinate,
        ]));
    }

    public function testLargeArticleBatchesAreChunked(): void
    {
        $coordinates = [];
        for ($i = 0; $i < 501; $i++) {
            $coordinates[] = '30023:' . self::PUBKEY . ':' . $i;
        }
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::exactly(2))->method('findByCoordinates')->willReturnCallback(function (array $tuples): array {
            self::assertLessThanOrEqual(500, count($tuples));
            $map = [];
            foreach ($tuples as $tuple) {
                $map['30023:' . self::PUBKEY . ':' . $tuple['slug']] = $this->article(30023, $tuple['slug'], null);
            }
            return $map;
        });
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByCoordinates');
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::once())->method('getMultipleMetadata')->with([self::PUBKEY])
            ->willReturn([self::PUBKEY => new UserMetadata(name: 'Author')]);

        self::assertCount(501, $this->provider($articles, $events, $metadata)->findByCoordinates($coordinates));
    }

    private function provider(ArticleRepository $articles, EventRepository $events, RedisCacheService $metadata): ReadingListContentPreviewProvider
    {
        return new ReadingListContentPreviewProvider($articles, $events, $metadata, new NostrKeyService());
    }

    private function article(int $kind, string $slug, ?string $title): Article
    {
        return (new Article())->setKind($kind)->setPubkey(self::PUBKEY)->setSlug($slug)->setTitle($title);
    }

    private function event(int $kind, string $slug, array $tags): Event
    {
        $event = new Event();
        $event->setKind($kind);
        $event->setPubkey(self::PUBKEY);
        $event->setTags([['d', $slug], ...$tags]);

        return $event;
    }
}
