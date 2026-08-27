<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\Event;
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

final class FetchEventFromRelaysHandlerTest extends TestCase
{
    public function testNaddrLookupEnrichesRelaysAndPublishesFound(): void
    {
        $pubkey = str_repeat('b', 64);
        $identifier = 'wiki-entry';
        $lookupKey = EventLookupKey::forNaddr(30818, $pubkey, $identifier);
        $rawEvent = $this->rawEvent(str_repeat('a', 64), 30818, $pubkey, $identifier);
        $persisted = $this->eventEntity($rawEvent);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findByNaddr')
            ->with(30818, $pubkey, $identifier)
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

        $hub = $this->hubExpecting($lookupKey, 'found', $rawEvent->id);

        $this->handler(
            $nostrClient,
            $eventRepository,
            $projector,
            $hub,
            $userRelayListService,
        )(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: 30818,
            pubkey: $pubkey,
            identifier: $identifier,
            relays: ['wss://hint.example'],
        ));
    }

    public function testPublishesNotFoundWhenRelaysReturnNoEvent(): void
    {
        $pubkey = str_repeat('c', 64);
        $identifier = 'missing';
        $lookupKey = EventLookupKey::forNaddr(30818, $pubkey, $identifier);

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
            kind: 30818,
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
        );
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
