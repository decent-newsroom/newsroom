<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ArticleFetchController;
use App\Controller\AuthorController;
use App\Controller\DefaultController;
use App\Controller\EventController;
use App\Controller\Reader\ArticleController;
use App\Controller\Reader\ChapterController;
use App\Entity\Event;
use App\Entity\VanityName;
use App\Repository\ArticleRepository;
use App\Repository\BannedPubkeyRepository;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\ArticleEventProjector;
use App\Service\ArticlePublicationIndexer;
use App\Service\BooksChapterLookup;
use App\Service\Cache\RedisCacheService;
use App\Service\ChapterParentPublicationResolver;
use App\Service\GenericEventProjector;
use App\Service\HighlightService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\UserRelayListService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\ArticleAccessService;
use App\Service\ReadingListNavigationService;
use App\Service\VanityNameService;
use App\Util\CommonMark\Converter;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Doctrine\ORM\EntityManagerInterface;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SuppressedAuthorRequestTest extends TestCase
{
    private const HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @dataProvider authorLinks */
    public function testAuthorBearingEventLinksStopBeforeAnyLookup(string $identifier, bool $banned): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::never())->method('findById');
        $repository->expects(self::never())->method('findByNaddr');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventById');
        $client->expects(self::never())->method('getEventByNaddr');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $relays = $this->createMock(UserRelayListService::class);
        $relays->expects(self::never())->method('getRelaysForEventLookupCacheOrDb');
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMetadata');
        $controller = new EventController($this->createMock(ArticleEventProjector::class), $this->policy($banned));

        $this->expectException(NotFoundHttpException::class);
        $controller->index(
            $identifier, new Request(), $metadata, new NostrLinkParser(new NullLogger()),
            new NullLogger(), $repository, $bus, $client,
            $this->createMock(GenericEventProjector::class), $relays,
        );
    }

    public static function authorLinks(): iterable
    {
        foreach ([false, true] as $banned) {
            yield [(new NostrNip19Service())->encodeAddr(self::HEX, 'spam', 30023), $banned];
            yield [(new NostrNip19Service())->encodeAddr(self::HEX, 'spam', 30040), $banned];
            yield [(string) Bech32::nevent(id: self::ID, author: self::HEX, kind: 1), $banned];
            yield [(string) Bech32::nprofile(pubkey: self::HEX), $banned];
        }
    }

    /** @dataProvider idOnlyLinks */
    public function testIdOnlyDbHitStopsBeforeMetadataOrRelayWork(string $identifier): void
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey(self::HEX);
        $event->setKind(1);
        $event->setContent('spam');
        $event->setCreatedAt(123);
        $event->setTags([]);
        $event->setSig(str_repeat('f', 128));
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findById')->with(self::ID)->willReturn($event);
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMetadata');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventById');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new EventController($this->createMock(ArticleEventProjector::class), $this->policy());

        $this->expectException(NotFoundHttpException::class);
        $controller->index(
            $identifier, new Request(), $metadata, new NostrLinkParser(new NullLogger()),
            new NullLogger(), $repository, $bus, $client,
            $this->createMock(GenericEventProjector::class), $this->createMock(UserRelayListService::class),
        );
    }

    public static function idOnlyLinks(): iterable
    {
        yield [(new NostrNip19Service())->encodeNote(self::ID)];
        yield [(string) Bech32::nevent(id: self::ID, kind: 1)];
    }

    public function testIdOnlyRelayHitIsRejectedBeforeProjection(): void
    {
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventById')->with(self::ID)
            ->willReturn((object) ['id' => self::ID, 'pubkey' => self::HEX, 'kind' => 1]);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->expects(self::never())->method('getMetadata');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new EventController($this->createMock(ArticleEventProjector::class), $this->policy());

        $this->expectException(NotFoundHttpException::class);
        $controller->index(
            (new NostrNip19Service())->encodeNote(self::ID), new Request(), $metadata,
            new NostrLinkParser(new NullLogger()), new NullLogger(),
            $this->createMock(EventRepository::class), $bus, $client, $projector,
            $this->createMock(UserRelayListService::class),
        );
    }

    public function testProfileStopsBeforeVanityLookupOrRedirect(): void
    {
        $vanity = $this->createMock(VanityNameService::class);
        $vanity->expects(self::never())->method('getActiveByNpub');
        $controller = new AuthorController(
            new NullLogger(), new NostrLinkParser(new NullLogger()), $vanity,
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(ArticleRepository::class), $this->policy(),
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->index((new NostrNip19Service())->encodeNpub(self::HEX));
    }

    public function testLegacyArticleAddressReturns404InsteadOfRedirecting(): void
    {
        $nip19 = new NostrNip19Service();
        $controller = new ArticleController($this->createMock(VanityNameService::class), $this->policy(true));

        $this->expectException(NotFoundHttpException::class);
        $controller->naddr($nip19->encodeAddr(self::HEX, 'spam', 30023), $nip19);
    }

    public function testFollowPackStopsBeforeContentOrRelayWork(): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::never())->method('getRepository');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(NotFoundHttpException::class);
        (new DefaultController())->followPackView(
            (new NostrNip19Service())->encodeNpub(self::HEX), 'spam', new Request(), $manager,
            $this->createMock(RedisCacheService::class), $this->createMock(ArticleRepository::class),
            $bus, $this->policy(),
        );
    }

    public function testVanityArticleStopsAfterIdentityResolutionBeforeLoadingArticle(): void
    {
        $identity = $this->createMock(VanityName::class);
        $identity->method('getNpub')->willReturn((new NostrNip19Service())->encodeNpub(self::HEX));
        $vanity = $this->createMock(VanityNameService::class);
        $vanity->expects(self::once())->method('getActiveByVanityName')->with('spam')->willReturn($identity);
        $controller = new ArticleController($vanity, $this->policy());

        $this->expectException(NotFoundHttpException::class);
        $method = new \ReflectionMethod(ArticleController::class, 'resolveVanityOrRedirect');
        $method->invoke($controller, null, 'spam', 'author-vanity-article-slug');
    }

    /** @dataProvider articleFrames */
    public function testArticleFramesDoNotSwallow404(string $frame): void
    {
        $controller = new ArticleController($this->createMock(VanityNameService::class), $this->policy());
        $npub = (new NostrNip19Service())->encodeNpub(self::HEX);
        $this->expectException(NotFoundHttpException::class);

        switch ($frame) {
            case 'aside':
                $indexer = $this->createMock(ArticlePublicationIndexer::class);
                $indexer->expects(self::never())->method('findPublicationsForArticle');
                $controller->articleAsideFrame('spam', $indexer, $npub);
                break;
            case 'navigation':
                $navigation = $this->createMock(ReadingListNavigationService::class);
                $navigation->expects(self::never())->method('findNavigation');
                $controller->articleListNavFrame('spam', $navigation, $npub);
                break;
            case 'comments':
                $controller->articleCommentsFrame('spam', $npub);
                break;
            case 'highlights':
                $highlights = $this->createMock(HighlightService::class);
                $highlights->expects(self::never())->method('getHighlightsForArticle');
                $controller->articleHighlightsFrame('spam', $npub, $highlights);
                break;
            case 'related':
                $manager = $this->createMock(EntityManagerInterface::class);
                $manager->expects(self::never())->method('getRepository');
                $identity = new NostrIdentityService(
                    $this->createMock(Nip19DecoderInterface::class),
                    $this->createMock(Nip19EncoderInterface::class),
                );
                $controller->articleRelatedFrame('spam', $npub, $manager, new ArticleAccessService($identity));
                break;
        }
    }

    public static function articleFrames(): iterable
    {
        foreach (['aside', 'navigation', 'comments', 'highlights', 'related'] as $frame) {
            yield [$frame];
        }
    }

    public function testChapterStopsBeforeDbBooksOrAsyncLookup(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::never())->method('findByNaddr');
        $books = $this->createMock(BooksChapterLookup::class);
        $books->expects(self::never())->method('find');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new ChapterController($this->policy(true));

        $this->expectException(NotFoundHttpException::class);
        $controller->show(
            (new NostrNip19Service())->encodeAddr(self::HEX, 'spam', 30041),
            $repository, $books, $bus, $this->createMock(Converter::class), new NullLogger(),
        );
    }

    public function testChapterParentFrameStopsBeforeParentLookup(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::never())->method('findReferencingEvents');
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::never())->method('request');
        $resolver = new ChapterParentPublicationResolver($repository, $http, 'https://books.example', new NullLogger());
        $controller = new ChapterController($this->policy());

        $this->expectException(NotFoundHttpException::class);
        $controller->parentFrame((new NostrNip19Service())->encodeAddr(self::HEX, 'spam', 30041), $resolver);
    }

    /** @dataProvider fetchRequests */
    public function testFetchApiReturns404WithoutNetworkOrProjection(array $data): void
    {
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventById');
        $client->expects(self::never())->method('getArticlesByCoordinates');
        $articles = $this->createMock(ArticleEventProjector::class);
        $articles->expects(self::never())->method('projectArticleFromEvent');
        $events = $this->createMock(GenericEventProjector::class);
        $events->expects(self::never())->method('projectEventFromNostrEvent');
        $controller = new ArticleFetchController($client, $articles, $events, $this->policy(true));

        $response = $controller->fetchArticle(new Request(content: json_encode($data)));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Event not found'], json_decode($response->getContent(), true));
    }

    public static function fetchRequests(): iterable
    {
        yield 'coordinate' => [['coordinate' => '30023:' . self::HEX . ':spam:with-colon']];
        yield 'parts' => [['pubkey' => self::HEX, 'slug' => 'spam']];
        yield 'id with author' => [['id' => self::ID, 'pubkey' => self::HEX]];
    }

    public function testFetchApiChecksActualAuthorOnIdOnlyRelayHit(): void
    {
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventById')->with(self::ID, [])
            ->willReturn((object) ['id' => self::ID, 'pubkey' => self::HEX, 'kind' => 30023]);
        $articles = $this->createMock(ArticleEventProjector::class);
        $articles->expects(self::never())->method('projectArticleFromEvent');
        $events = $this->createMock(GenericEventProjector::class);
        $events->expects(self::never())->method('projectEventFromNostrEvent');
        $controller = new ArticleFetchController($client, $articles, $events, $this->policy());

        $response = $controller->fetchArticle(new Request(content: json_encode(['id' => self::ID])));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAllowedFetchStillProjectsTheArticle(): void
    {
        $coordinate = '30023:' . self::HEX . ':valid:identifier';
        $event = (object) ['id' => self::ID, 'pubkey' => self::HEX, 'kind' => 30023];
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getArticlesByCoordinates')->with([$coordinate])
            ->willReturn([$coordinate => $event]);
        $articles = $this->createMock(ArticleEventProjector::class);
        $articles->expects(self::once())->method('projectArticleFromEvent')->with($event, 'api-fetch');
        $controller = new ArticleFetchController(
            $client, $articles, $this->createMock(GenericEventProjector::class), $this->policy(false, false),
        );

        $response = $controller->fetchArticle(new Request(content: json_encode(['coordinate' => $coordinate])));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(json_decode($response->getContent(), true)['success']);
    }

    private function policy(bool $banned = false, bool $muted = true): ContentAuthorAccessPolicy
    {
        $users = $this->createMock(UserEntityRepository::class);
        $users->method('isAdminMuted')->willReturn($muted);
        $bans = $this->createMock(BannedPubkeyRepository::class);
        $bans->method('isBanned')->willReturn($banned);

        return new ContentAuthorAccessPolicy($users, $bans, new NostrKeyService(), new NullLogger());
    }
}
