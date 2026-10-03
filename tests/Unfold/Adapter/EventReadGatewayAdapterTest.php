<?php

declare(strict_types=1);

namespace App\Tests\Unfold\Adapter;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\Unfold\EventReadGatewayAdapter;
use PHPUnit\Framework\TestCase;

final class EventReadGatewayAdapterTest extends TestCase
{
    public function testDatabaseEventsAreConvertedWithoutLeakingEntities(): void
    {
        $event = new Event();
        $event->setId('event-id');
        $event->setPubkey('ABCDEF');
        $event->setKind(30040);
        $event->setContent('content');
        $event->setTags([['d', 'main']]);
        $event->setCreatedAt(123);
        $event->setSig('signature');

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(30040, 'abcdef', 'main')
            ->willReturn($event);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventByNaddr');

        $result = (new EventReadGatewayAdapter($repository, $client))
            ->findByCoordinate('30040:ABCDEF:main');

        self::assertNotNull($result);
        self::assertSame('event-id', $result->id);
        self::assertSame('abcdef', $result->pubkey);
        self::assertSame(123, $result->createdAt);
        self::assertNotSame($event, $result);
    }

    public function testRelayFallbackReturnsTheContractDto(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())
            ->method('getEventByNaddr')
            ->with([
                'kind' => 30040,
                'pubkey' => 'abcdef',
                'identifier' => 'main',
                'relays' => [],
            ], false, false, null, null, true)
            ->willReturn((object) [
                'id' => 'relay-event',
                'pubkey' => 'ABCDEF',
                'kind' => 30040,
                'content' => '',
                'tags' => [['d', 'main']],
                'created_at' => 456,
                'sig' => 'relay-signature',
            ]);

        $result = (new EventReadGatewayAdapter($repository, $client))
            ->findByCoordinate('30040:ABCDEF:main');

        self::assertNotNull($result);
        self::assertSame('relay-event', $result->id);
        self::assertSame(456, $result->createdAt);
    }

    public function testCleanEmptyLookupRemainsMissing(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventByNaddr')
            ->with([
                'kind' => 30040,
                'pubkey' => 'abcdef',
                'identifier' => 'main',
                'relays' => ['wss://hint.example.test'],
            ], false, false, null, null, true)
            ->willReturn(null);

        self::assertNull((new EventReadGatewayAdapter($repository, $client))
            ->findByCoordinate('30040:ABCDEF:main', ['wss://hint.example.test']));
    }

    public function testMalformedSourceTagsAreNotSilentlyRepaired(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->method('getEventByNaddr')->willReturn((object) [
            'id' => 'source',
            'pubkey' => 'abcdef',
            'kind' => 30040,
            'content' => '',
            'tags' => [['d', 'main'], ['unknown', 1]],
            'created_at' => 456,
            'sig' => '',
        ]);

        $this->expectException(\UnexpectedValueException::class);
        (new EventReadGatewayAdapter($repository, $client))->findByCoordinate('30040:abcdef:main');
    }

    public function testTimedOutLookupPropagatesAsInfrastructureFailure(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventByNaddr')
            ->with(self::anything(), false, false, null, null, true)
            ->willThrowException(new \RuntimeException('Nostr coordinate lookup timed out.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nostr coordinate lookup timed out.');
        (new EventReadGatewayAdapter($repository, $client))->findByCoordinate('30040:ABCDEF:main');
    }

    public function testLocalIndexReadUsesSharedWizardLookupWithoutRelayFallback(): void
    {
        $event = new Event();
        $event->setId('stored-index');
        $event->setPubkey('abcdef');
        $event->setKind(30040);
        $event->setTags([['d', ' main:2026 ']]);
        $event->setCreatedAt(123);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::exactly(2))->method('findLatestIndexByIdentifier')
            ->with('abcdef', ' main:2026 ')
            ->willReturnOnConsecutiveCalls($event, null);
        $repository->expects(self::never())->method('findByNaddr');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventByNaddr');
        $client->expects(self::never())->method('getEventsByCoordinates');
        $gateway = new EventReadGatewayAdapter($repository, $client);

        self::assertSame('stored-index', $gateway->findLocalByCoordinate('30040:ABCDEF: main:2026 ')?->id);
        self::assertNull($gateway->findLocalByCoordinate('30040:ABCDEF: main:2026 '));
    }

    public function testLocalLeafReadRemainsMissingWithoutRelayFallback(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findByNaddr')->with(30817, 'abcdef', 'Spec')->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventByNaddr');
        $client->expects(self::never())->method('getEventsByCoordinates');

        self::assertNull((new EventReadGatewayAdapter($repository, $client))->findLocalByCoordinate('30817:ABCDEF:Spec'));
    }

    public function testLocalBatchOnlyReturnsPersistedMatchingLeaves(): void
    {
        $event = new Event();
        $event->setId('stored-leaf');
        $event->setPubkey('abcdef');
        $event->setKind(30817);
        $event->setTags([['d', 'Spec'], ['title', 'Specification']]);
        $event->setCreatedAt(123);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findByCoordinates')
            ->with(['30817:abcdef:Spec', '30023:abcdef:missing'])
            ->willReturn(['30817:abcdef:Spec' => $event]);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('getEventByNaddr');
        $client->expects(self::never())->method('getEventsByCoordinates');

        $result = (new EventReadGatewayAdapter($repository, $client))->findLocalByCoordinates([
            '30817:ABCDEF:Spec', '30023:abcdef:missing', '30817:abcdef:Spec',
        ]);

        self::assertSame(['30817:abcdef:Spec'], array_keys($result));
        self::assertSame('stored-leaf', $result['30817:abcdef:Spec']->id);
    }

    public function testPublicBatchStillFetchesMissingCoordinatesFromRelays(): void
    {
        $event = new Event();
        $event->setId('stored-leaf');
        $event->setPubkey('abcdef');
        $event->setKind(30817);
        $event->setTags([['d', 'Spec']]);
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByCoordinates')->willReturn(['30817:abcdef:Spec' => $event]);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventsByCoordinates')
            ->with(['30023:abcdef:missing'])
            ->willReturn([(object) [
                'id' => 'relay-leaf', 'pubkey' => 'abcdef', 'kind' => 30023,
                'content' => '', 'tags' => [['d', 'missing']], 'created_at' => 123, 'sig' => '',
            ]]);

        $result = (new EventReadGatewayAdapter($repository, $client))->findByCoordinates([
            '30817:abcdef:Spec', '30023:abcdef:missing',
        ]);

        self::assertSame('stored-leaf', $result['30817:abcdef:Spec']->id);
        self::assertSame('relay-leaf', $result['30023:abcdef:missing']->id);
    }
}
