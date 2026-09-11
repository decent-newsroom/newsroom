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
            ])
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
}
