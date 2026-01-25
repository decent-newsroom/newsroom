<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\GenericEventProjector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GenericEventProjectorTest extends TestCase
{
    private EntityManagerInterface $em;
    private EventRepository $eventRepository;
    private LoggerInterface $logger;
    private GenericEventProjector $projector;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->projector = new GenericEventProjector(
            $this->em,
            $this->eventRepository,
            $this->logger
        );
    }

    public function testProjectEventFromNostrEvent(): void
    {
        // Create a mock Nostr event
        $nostrEvent = (object)[
            'id' => 'abc123def456',
            'kind' => 30040,
            'pubkey' => 'd475ce4b3977507130f42c7f86346ef936800f3ae74d5ecf8089280cdc1923e9',
            'content' => '{"name":"My Magazine"}',
            'created_at' => 1737723600,
            'tags' => [
                ['d', 'my-magazine'],
                ['title', 'My Magazine'],
            ],
            'sig' => 'sig123456'
        ];

        // Event doesn't exist yet
        $this->eventRepository
            ->expects($this->once())
            ->method('find')
            ->with('abc123def456')
            ->willReturn(null);

        // Expect persist and flush
        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(Event::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        // Project the event
        $result = $this->projector->projectEventFromNostrEvent($nostrEvent, 'ws://strfry:7777');

        // Verify result
        $this->assertInstanceOf(Event::class, $result);
        $this->assertEquals('abc123def456', $result->getId());
        $this->assertEquals(30040, $result->getKind());
        $this->assertEquals('d475ce4b3977507130f42c7f86346ef936800f3ae74d5ecf8089280cdc1923e9', $result->getPubkey());
    }

    public function testProjectEventFromNostrEventSkipsDuplicate(): void
    {
        // Create a mock Nostr event
        $nostrEvent = (object)[
            'id' => 'abc123def456',
            'kind' => 30040,
            'pubkey' => 'd475ce4b3977507130f42c7f86346ef936800f3ae74d5ecf8089280cdc1923e9',
            'content' => '',
            'created_at' => 1737723600,
            'tags' => [],
            'sig' => 'sig123456'
        ];

        // Event already exists
        $existingEvent = new Event();
        $existingEvent->setId('abc123def456');
        $existingEvent->setEventId('abc123def456');
        $existingEvent->setKind(30040);

        $this->eventRepository
            ->expects($this->once())
            ->method('find')
            ->with('abc123def456')
            ->willReturn($existingEvent);

        // Should NOT persist or flush
        $this->em
            ->expects($this->never())
            ->method('persist');

        $this->em
            ->expects($this->never())
            ->method('flush');

        // Project the event
        $result = $this->projector->projectEventFromNostrEvent($nostrEvent, 'ws://strfry:7777');

        // Should return the existing event
        $this->assertSame($existingEvent, $result);
    }

    public function testProjectEventFromNostrEventThrowsOnMissingId(): void
    {
        $nostrEvent = (object)[
            'kind' => 30040,
            // Missing 'id'
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid event: missing required fields');

        $this->projector->projectEventFromNostrEvent($nostrEvent, 'ws://strfry:7777');
    }

    public function testProjectEventFromNostrEventThrowsOnMissingKind(): void
    {
        $nostrEvent = (object)[
            'id' => 'abc123',
            // Missing 'kind'
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid event: missing required fields');

        $this->projector->projectEventFromNostrEvent($nostrEvent, 'ws://strfry:7777');
    }

    public function testGetEventCountByKind(): void
    {
        $this->eventRepository
            ->expects($this->once())
            ->method('count')
            ->with(['kind' => 30040])
            ->willReturn(42);

        $count = $this->projector->getEventCountByKind(30040);

        $this->assertEquals(42, $count);
    }
}
