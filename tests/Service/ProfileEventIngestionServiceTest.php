<?php

namespace App\Tests\Service;

use App\Entity\Event;
use App\Service\ProfileEventIngestionService;
use App\Service\ProfileUpdateDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProfileEventIngestionService
 */
class ProfileEventIngestionServiceTest extends TestCase
{
    private ProfileUpdateDispatcher $profileUpdateDispatcher;
    private LoggerInterface $logger;
    private ProfileEventIngestionService $service;

    protected function setUp(): void
    {
        $this->profileUpdateDispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ProfileEventIngestionService($this->profileUpdateDispatcher, $this->logger);
    }

    public function testHandleEventIngestionWithMetadataEvent(): void
    {
        $event = new Event();
        $event->setId('test123');
        $event->setKind(0); // Metadata event
        $event->setPubkey('abc123def456');
        $event->setContent('{"name":"Test User"}');
        $event->setCreatedAt(time());
        $event->setTags([]);
        $event->setSig('sig123');

        $this->profileUpdateDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($event->getPubkey())
            ->willReturn(true);

        $this->service->handleEventIngestion($event);
    }

    public function testHandleEventIngestionWithRelayListEvent(): void
    {
        $event = new Event();
        $event->setId('test456');
        $event->setKind(10002); // Relay list event
        $event->setPubkey('abc123def456');
        $event->setContent('');
        $event->setCreatedAt(time());
        $event->setTags([
            ['r', 'wss://relay.example.com'],
            ['r', 'wss://relay2.example.com', 'write']
        ]);
        $event->setSig('sig456');

        $this->profileUpdateDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($event->getPubkey())
            ->willReturn(true);

        $this->service->handleEventIngestion($event);
    }

    public function testHandleEventIngestionWithNonProfileEvent(): void
    {
        $event = new Event();
        $event->setId('test789');
        $event->setKind(1); // Text note (not a profile event)
        $event->setPubkey('abc123def456');
        $event->setContent('Hello world');
        $event->setCreatedAt(time());
        $event->setTags([]);
        $event->setSig('sig789');

        $this->profileUpdateDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $this->service->handleEventIngestion($event);
    }

    public function testHandleBatchEventIngestionDeduplicates(): void
    {
        $pubkey = 'abc123def456';

        // Create multiple events from same pubkey
        $event1 = $this->createProfileEvent(0, $pubkey);
        $event2 = $this->createProfileEvent(0, $pubkey);
        $event3 = $this->createProfileEvent(10002, $pubkey);

        $events = [$event1, $event2, $event3];

        // Expect only one pubkey to be included in the batch.
        $this->profileUpdateDispatcher
            ->expects($this->once())
            ->method('dispatchBatch')
            ->with([$pubkey])
            ->willReturn(1);

        $this->service->handleBatchEventIngestion($events);
    }

    public function testHandleBatchEventIngestionWithMultiplePubkeys(): void
    {
        $pubkey1 = 'abc123';
        $pubkey2 = 'def456';

        $event1 = $this->createProfileEvent(0, $pubkey1);
        $event2 = $this->createProfileEvent(0, $pubkey2);
        $event3 = $this->createProfileEvent(1, $pubkey1); // Non-profile event

        $events = [$event1, $event2, $event3];

        $this->profileUpdateDispatcher
            ->expects($this->once())
            ->method('dispatchBatch')
            ->with([$pubkey1, $pubkey2])
            ->willReturn(2);

        $this->service->handleBatchEventIngestion($events);
    }

    private function createProfileEvent(int $kind, string $pubkey): Event
    {
        $event = new Event();
        $event->setId(bin2hex(random_bytes(16)));
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setContent($kind === 0 ? '{"name":"Test"}' : '');
        $event->setCreatedAt(time());
        $event->setTags([]);
        $event->setSig('sig_' . $event->getId());

        return $event;
    }
}
