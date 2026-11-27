<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfDirectoryBooks;
use App\Bookshelf\BookshelfRelayBookLoader;
use App\Controller\Bookshelf\MyBooksController;
use App\Entity\Event;
use App\Entity\User;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\RelayPoolInterface;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\RelayQueryResult;
use App\Service\Nostr\RelayRegistry;
use DecentNewsroom\BookshelfBundle\Contract\DirectoryEventStoreInterface;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryUnavailableException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use Innis\Nostr\Core\Domain\Entity\Event as NostrEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class BookshelfDirectoryBooksTest extends TestCase
{
    public function testItUsesOnlyExactMissesInMercuryAndOneLocalRelayRequest(): void
    {
        $firstAuthor = str_repeat('a', 64);
        $secondAuthor = str_repeat('b', 64);
        $pin = str_repeat('e', 64);
        $exactId = str_repeat('f', 64);
        $local = 'ws://strfry:7777';
        $references = $this->references([
            ['a', '30040:' . $firstAuthor . ':indexed'],
            ['a', '30040:' . $firstAuthor . ':mercury'],
            ['a', '30040:' . $firstAuthor . ':pinned', 'wss://hint.example', $pin],
            ['a', '30040:' . $secondAuthor . ':local:book', 'wss://hint.example'],
            ['e', $exactId, 'wss://hint.example'],
        ]);
        $booksApi = $this->createMock(MercuryApiClientInterface::class);
        $booksApi->expects(self::once())->method('getPublicationEventsByIds')->with([$pin, $exactId])
            ->willReturn([$this->event('e', $secondAuthor, 'wrong-coordinate')]);
        $booksApi->expects(self::once())->method('getPublicationsByAuthors')
            ->with([$firstAuthor, $secondAuthor], 6, ['indexed', 'mercury', 'local:book'])
            ->willReturn([
                $this->event('2', $firstAuthor, 'indexed'),
                $this->event('1', $firstAuthor, 'indexed'),
                $this->event('3', $firstAuthor, 'local:book'),
            ]);
        $mercury = $this->createMock(MercuryApiClientInterface::class);
        $mercury->expects(self::once())->method('getPublicationEventsByIds')->with([$pin, $exactId])->willReturn([]);
        $mercury->expects(self::once())->method('getPublicationsByAuthors')
            ->with([$firstAuthor, $secondAuthor], 4, ['mercury', 'local:book'])
            ->willReturn([$this->event('4', $firstAuthor, 'mercury')]);

        $registry = $this->createStub(RelayRegistry::class);
        $registry->method('getLocalRelay')->willReturn($local);
        $pool = $this->createMock(RelayPoolInterface::class);
        $pool->expects(self::once())->method('executeRequest')->with(self::callback(
            static function (RelayQueryRequest $request) use ($local, $secondAuthor, $pin, $exactId): bool {
                self::assertSame([$local], $request->getRelaySet()->getUrls());
                self::assertSame(2, $request->getTimeout());
                self::assertSame([
                    ['kinds' => [30040], 'ids' => [$pin, $exactId], 'limit' => 2],
                    ['kinds' => [30040], 'authors' => [$secondAuthor], '#d' => ['local:book'], 'limit' => 1],
                ], $request->getFilters());

                return true;
            },
        ))->willReturn([$local => new RelayQueryResult($local, [
            NostrEvent::fromArray($this->event('f', $secondAuthor, 'exact')->toArray()),
            NostrEvent::fromArray($this->event('5', $secondAuthor, 'local:book')->toArray()),
            NostrEvent::fromArray($this->event('6', $firstAuthor, 'pinned')->toArray()),
        ], false, 'Idle timeout before EOSE')]);

        $result = $this->service($booksApi, $mercury, $registry, $pool)->resolve($references);

        self::assertSame(['indexed', 'mercury', 'local:book', 'exact'], array_column($result['books'], 'title'));
        self::assertSame(['1', '4', '5', 'f'], array_map(static fn (array $book): string => $book['id'][0], $result['books']));
        self::assertSame(1, $result['missing']);
        self::assertTrue($result['available']);
    }

    public function testItRetainsPartialHitsWhenTheProvidersFail(): void
    {
        $author = str_repeat('a', 64);
        $event = $this->event('b', $author, 'indexed');
        $booksApi = $this->createMock(MercuryApiClientInterface::class);
        $booksApi->expects(self::once())->method('getPublicationEventsByIds')->with([$event->id])->willReturn([$event]);
        $booksApi->expects(self::once())->method('getPublicationsByAuthors')->willThrowException(new MercuryUnavailableException('offline'));
        $mercury = $this->createMock(MercuryApiClientInterface::class);
        $mercury->expects(self::never())->method('getPublicationEventsByIds');
        $mercury->expects(self::once())->method('getPublicationsByAuthors')->with([$author], 1, ['missing'])
            ->willThrowException(new MercuryUnavailableException('offline'));

        $result = $this->service($booksApi, $mercury)->resolve($this->references([
            ['e', $event->id], ['a', '30040:' . $author . ':missing'],
        ]));

        self::assertSame([$event->id], array_column($result['books'], 'id'));
        self::assertSame(1, $result['missing']);
        self::assertTrue($result['available']);
    }

    public function testControllerResolvesOnlyTheRequestedPageAndPreservesAllEditableTags(): void
    {
        $owner = str_repeat('a', 64);
        $identifier = BookshelfDirectoryService::IDENTIFIER;
        $ids = array_map(static fn (int $position): string => sprintf('%064x', $position), range(1, 12));
        $tags = [['d', $identifier], ...array_map(static fn (string $id): array => ['e', $id], $ids)];
        $directory = new Event();
        $directory->setDTag($identifier);
        $directory->setTags($tags);
        $store = $this->createMock(DirectoryEventStoreInterface::class);
        $store->expects(self::once())->method('findAllByPubkeyAndKind')->with($owner, 30045, 50)->willReturn([$directory]);
        $booksApi = $this->createMock(MercuryApiClientInterface::class);
        $booksApi->expects(self::once())->method('getPublicationEventsByIds')->with(array_slice($ids, 10))->willReturn([]);
        $booksApi->expects(self::never())->method('getPublicationsByAuthors');
        $mercury = $this->createStub(MercuryApiClientInterface::class);
        $user = new User();
        $user->setNpub((new NostrNip19Service())->encodeNpub($owner));
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->with('@Bookshelf/bookshelf/my_books.html.twig', self::callback(
            static function (array $parameters) use ($tags): bool {
                self::assertSame($tags, $parameters['directoryTags']);
                self::assertSame(12, $parameters['referenceCount']);
                self::assertSame(2, $parameters['missingBookCount']);
                self::assertSame(2, $parameters['page']);
                self::assertTrue($parameters['hasPrevious']);
                self::assertFalse($parameters['hasNext']);

                return true;
            },
        ))->willReturn('My Books page');
        $container = new Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('twig', $twig);
        $controller = new MyBooksController();
        $controller->setContainer($container);
        $response = $controller->index(new Request(['page' => '2']), new BookshelfDirectoryService($store), $this->service($booksApi, $mercury));

        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function service(
        MercuryApiClientInterface $primary,
        MercuryApiClientInterface $fallback,
        ?RelayRegistry $registry = null,
        ?RelayPoolInterface $pool = null,
    ): BookshelfDirectoryBooks {
        if ($pool === null) {
            $pool = $this->createMock(RelayPoolInterface::class);
            $pool->expects(self::never())->method('executeRequest');
        }
        $logger = new NullLogger();

        return new BookshelfDirectoryBooks(
            $primary, $fallback, $registry ?? $this->createStub(RelayRegistry::class), $pool,
            new BookshelfRelayBookLoader($this->createStub(NostrClient::class), $logger),
            $logger,
        );
    }

    /** @param list<list<string>> $tags @return list<array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> */
    private function references(array $tags): array
    {
        return new BookshelfDirectoryService($this->createStub(DirectoryEventStoreInterface::class))->extractBookReferences($tags);
    }

    private function event(string $id, string $author, string $identifier): MercuryEvent
    {
        return new MercuryEvent(str_repeat($id, 64), $author, 30040, 20, '', '', [
            ['d', $identifier], ['title', $identifier], ['a', '30041:' . $author . ':chapter'],
        ]);
    }
}
