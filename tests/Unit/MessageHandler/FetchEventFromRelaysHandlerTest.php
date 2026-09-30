<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\MessageHandler\FetchEventFromRelaysHandler;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\GenericEventProjector;
use App\Service\Nostr\EventLookupKey;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class FetchEventFromRelaysHandlerTest extends TestCase
{
    public function testNaddrLookupEnrichesRelaysAndPublishesFound(): void
    {
        $pubkey = str_repeat('b', 64);
        $identifier = 'wiki-entry';
        $lookupKey = EventLookupKey::forNaddr(KindsEnum::WIKI->value, $pubkey, $identifier);
        $rawEvent = $this->rawEvent(str_repeat('a', 64), KindsEnum::WIKI->value, $pubkey, $identifier);
        $persisted = $this->eventEntity($rawEvent);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::WIKI->value, $pubkey, $identifier)
            ->willReturn(null);

        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->expects(self::once())
            ->method('getRelaysForFetching')
            ->with($pubkey)
            ->willReturn(['wss://author.example', 'wss://hint.example']);

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventByNaddr')
            ->willReturnCallback(function (
                array $decoded,
                bool $hintOnly = false,
                bool $allowRelayListNetworkFetch = true,
                ?int $gatewayTimeout = null,
                ?int $directTimeout = null,
            ) use ($rawEvent): object {
                TestCase::assertSame(['wss://hint.example', 'wss://author.example'], $decoded['relays']);
                TestCase::assertFalse($hintOnly);
                TestCase::assertTrue($allowRelayListNetworkFetch);
                TestCase::assertSame(15, $gatewayTimeout);
                TestCase::assertSame(10, $directTimeout);

                return $rawEvent;
            });

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())
            ->method('projectEventFromNostrEvent')
            ->with($rawEvent, 'wss://hint.example')
            ->willReturn($persisted);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $hub = $this->hubExpecting($lookupKey, 'found', $rawEvent->id);

        $this->handler(
            $nostrClient,
            $eventRepository,
            $projector,
            $hub,
            $userRelayListService,
            httpClient: $httpClient,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: KindsEnum::WIKI->value,
            pubkey: $pubkey,
            identifier: $identifier,
            relays: ['wss://hint.example'],
        ));
    }

    public function testPublishesNotFoundWhenRelaysReturnNoEvent(): void
    {
        $pubkey = str_repeat('c', 64);
        $identifier = 'missing';
        $lookupKey = EventLookupKey::forNaddr(KindsEnum::WIKI->value, $pubkey, $identifier);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findByNaddr')
            ->willReturn(null);

        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->expects(self::once())
            ->method('getRelaysForFetching')
            ->willReturn([]);

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventByNaddr')
            ->willReturn(null);

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())
            ->method('projectEventFromNostrEvent');

        $this->handler(
            $nostrClient,
            $eventRepository,
            $projector,
            $this->hubExpecting($lookupKey, 'not_found'),
            $userRelayListService,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: KindsEnum::WIKI->value,
            pubkey: $pubkey,
            identifier: $identifier,
        ));
    }

    public function testPublishesErrorWhenProjectionFailsAfterRelayHit(): void
    {
        $eventId = str_repeat('d', 64);
        $pubkey = str_repeat('e', 64);
        $lookupKey = EventLookupKey::forNevent($eventId);
        $rawEvent = $this->rawEvent($eventId, 1, $pubkey, '');

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findById')
            ->with($eventId)
            ->willReturn(null);

        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->expects(self::once())
            ->method('getRelaysForFetching')
            ->with($pubkey)
            ->willReturn(['wss://author.example']);

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventById')
            ->willReturn($rawEvent);

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())
            ->method('projectEventFromNostrEvent')
            ->willThrowException(new \RuntimeException('database unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::anything(),
                self::callback(static fn(array $context): bool => ($context['event_id'] ?? null) === $eventId && ($context['kind'] ?? null) === 1),
            );

        $this->handler(
            $nostrClient,
            $eventRepository,
            $projector,
            $this->hubExpecting($lookupKey, 'error'),
            $userRelayListService,
            $logger,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'nevent',
            eventId: $eventId,
            pubkey: $pubkey,
            relays: ['wss://hint.example'],
        ));
    }

    public function testPublicationChapterFetchInvalidatesMagazineAndChapterCaches(): void
    {
        $pubkey = str_repeat('1', 64);
        $identifier = 'intro';
        $lookupKey = EventLookupKey::forNaddr(30041, $pubkey, $identifier);
        $rawEvent = $this->rawEvent(str_repeat('2', 64), 30041, $pubkey, $identifier);
        $persisted = $this->eventEntity($rawEvent);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findByNaddr')
            ->with(30041, $pubkey, $identifier)
            ->willReturn(null);

        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->expects(self::once())
            ->method('getRelaysForFetching')
            ->with($pubkey)
            ->willReturn([]);

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())
            ->method('getEventByNaddr')
            ->willReturn($rawEvent);

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())
            ->method('projectEventFromNostrEvent')
            ->willReturn($persisted);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::exactly(2))
            ->method('deleteItem')
            ->withConsecutive(
                ['magazine_chapters_frame_weekly'],
                ['chapter_' . $rawEvent->id],
            )
            ->willReturn(true);

        $this->handler(
            $nostrClient,
            $eventRepository,
            $projector,
            $this->hubExpecting($lookupKey, 'found', $rawEvent->id),
            $userRelayListService,
            cache: $cache,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: 30041,
            pubkey: $pubkey,
            identifier: $identifier,
            mag: 'weekly',
        ));
    }

    public function testMissingChapterIsFetchedFromBooksApiBeforeRelays(): void
    {
        $pubkey = str_repeat('5', 64);
        $identifier = 'intro';
        $lookupKey = EventLookupKey::forNaddr(30041, $pubkey, $identifier);
        $rawEvent = $this->rawEvent(str_repeat('6', 64), 30041, $pubkey, $identifier);
        $persisted = $this->eventEntity($rawEvent);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())->method('findByNaddr')->willReturn(null);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://books.example/books/api/events/filter', self::callback(
                static fn (array $options): bool => $options['json'] === [
                    'authors' => [$pubkey],
                    'kinds' => [30041],
                    '#d' => [$identifier],
                    'limit' => 10,
                ],
            ))
            ->willReturn($this->booksResponse([(array) $rawEvent]));

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::never())->method('getEventByNaddr');
        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->expects(self::never())->method('getRelaysForFetching');

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())
            ->method('projectEventFromNostrEvent')
            ->with(self::callback(static fn (object $event): bool => (array) $event === (array) $rawEvent), 'books-api')
            ->willReturn($persisted);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())->method('deleteItem')->with('chapter_' . $rawEvent->id)->willReturn(true);

        $this->handler(
            $nostrClient,
            $eventRepository,
            $projector,
            $this->hubExpecting($lookupKey, 'found', $rawEvent->id),
            $userRelayListService,
            cache: $cache,
            httpClient: $httpClient,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: 30041,
            pubkey: $pubkey,
            identifier: $identifier,
        ));
    }

    public function testBooksApiIgnoresWrongCoordinateAndFallsBackToRelays(): void
    {
        $pubkey = str_repeat('7', 64);
        $identifier = 'intro';
        $lookupKey = EventLookupKey::forNaddr(30041, $pubkey, $identifier);
        $rawEvent = $this->rawEvent(str_repeat('8', 64), 30041, $pubkey, $identifier);
        $wrongEvent = $this->rawEvent(str_repeat('9', 64), 30041, $pubkey, 'other');

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->method('findByNaddr')->willReturn(null);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')
            ->willReturn($this->booksResponse([(array) $wrongEvent]));
        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->method('getRelaysForFetching')->willReturn([]);
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())->method('getEventByNaddr')->willReturn($rawEvent);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())->method('projectEventFromNostrEvent')
            ->with($rawEvent, 'async-fetch')
            ->willReturn($this->eventEntity($rawEvent));

        $this->handler($nostrClient, $eventRepository, $projector,
            $this->hubExpecting($lookupKey, 'found', $rawEvent->id), $userRelayListService,
            httpClient: $httpClient,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: 30041,
            pubkey: $pubkey,
            identifier: $identifier,
        ));
    }

    public function testBooksApiFailureFallsBackToRelays(): void
    {
        $pubkey = str_repeat('a', 64);
        $lookupKey = EventLookupKey::forNaddr(30041, $pubkey, 'intro');
        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->method('findByNaddr')->willReturn(null);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->willReturn($response);
        $userRelayListService = $this->createMock(UserRelayListService::class);
        $userRelayListService->method('getRelaysForFetching')->willReturn([]);
        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::once())->method('getEventByNaddr')->willReturn(null);

        $this->handler($nostrClient, $eventRepository, $this->createMock(GenericEventProjector::class),
            $this->hubExpecting($lookupKey, 'not_found'), $userRelayListService,
            httpClient: $httpClient,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: 30041,
            pubkey: $pubkey,
            identifier: 'intro',
        ));
    }

    public function testAlreadyStoredPublicationChapterInvalidatesCacheAndPublishesFound(): void
    {
        $pubkey = str_repeat('3', 64);
        $identifier = 'intro';
        $lookupKey = EventLookupKey::forNaddr(30041, $pubkey, $identifier);
        $event = $this->eventEntity($this->rawEvent(str_repeat('4', 64), 30041, $pubkey, $identifier));

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findByNaddr')
            ->with(30041, $pubkey, $identifier)
            ->willReturn($event);

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects(self::never())->method('getEventByNaddr');
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::exactly(2))
            ->method('deleteItem')
            ->withConsecutive(
                ['magazine_chapters_frame_weekly'],
                ['chapter_' . $event->getId()],
            )
            ->willReturn(true);

        $this->handler(
            $nostrClient,
            $eventRepository,
            $this->createMock(GenericEventProjector::class),
            $this->hubExpecting($lookupKey, 'found', $event->getId()),
            $this->createMock(UserRelayListService::class),
            cache: $cache,
            httpClient: $httpClient,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: 30041,
            pubkey: $pubkey,
            identifier: $identifier,
            mag: 'weekly',
        ));
    }

    private function handler(
        NostrClient $nostrClient,
        EventRepository $eventRepository,
        GenericEventProjector $projector,
        HubInterface $hub,
        UserRelayListService $userRelayListService,
        ?LoggerInterface $logger = null,
        ?CacheItemPoolInterface $cache = null,
        ?HttpClientInterface $httpClient = null,
    ): FetchEventFromRelaysHandler {
        return new FetchEventFromRelaysHandler(
            $nostrClient,
            $eventRepository,
            $projector,
            $this->createMock(ArticleEventProjector::class),
            $userRelayListService,
            $hub,
            $cache ?? $this->createMock(CacheItemPoolInterface::class),
            $logger ?? $this->createMock(LoggerInterface::class),
            $httpClient ?? $this->emptyBooksClient(),
            'https://books.example',
        );
    }

    private function emptyBooksClient(): HttpClientInterface
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($this->booksResponse([]));

        return $client;
    }

    /** @param list<array<string, mixed>> $events */
    private function booksResponse(array $events): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->with(false)->willReturn($events);

        return $response;
    }

    private function hubExpecting(string $lookupKey, string $status, ?string $eventId = null): HubInterface
    {
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::once())
            ->method('publish')
            ->with(self::callback(function (Update $update) use ($lookupKey, $status, $eventId): bool {
                $data = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

                return $update->getTopics() === [EventLookupKey::topic($lookupKey)]
                    && $data['status'] === $status
                    && ($data['eventId'] ?? null) === $eventId;
            }))
            ->willReturn('update-id');

        return $hub;
    }

    private function rawEvent(string $eventId, int $kind, string $pubkey, string $identifier): object
    {
        return (object) [
            'id' => $eventId,
            'kind' => $kind,
            'pubkey' => $pubkey,
            'content' => '',
            'created_at' => 123,
            'tags' => $identifier === '' ? [] : [['d', $identifier]],
            'sig' => str_repeat('f', 128),
        ];
    }

    private function eventEntity(object $rawEvent): Event
    {
        $event = new Event();
        $event->setId($rawEvent->id);
        $event->setKind((int) $rawEvent->kind);
        $event->setPubkey($rawEvent->pubkey);
        $event->setContent($rawEvent->content);
        $event->setCreatedAt((int) $rawEvent->created_at);
        $event->setTags($rawEvent->tags);
        $event->setSig($rawEvent->sig);

        return $event;
    }
}
