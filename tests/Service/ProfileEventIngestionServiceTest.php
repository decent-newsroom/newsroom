<?php

namespace App\Tests\Service;

use App\Entity\Event;
use App\Message\UpdateProfileProjectionMessage;
use App\Service\ProfileEventIngestionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests for ProfileEventIngestionService
 */
class ProfileEventIngestionServiceTest extends TestCase
{
    private MessageBusInterface $messageBus;
    private LoggerInterface $logger;
    private ProfileEventIngestionService $service;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ProfileEventIngestionService($this->messageBus, $this->logger);
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

        // Expect message to be dispatched
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($event) {
                return $message instanceof UpdateProfileProjectionMessage
                    && $message->getPubkeyHex() === $event->getPubkey();
            }))
            ->willReturn(new Envelope(new \stdClass()));

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

        // Expect message to be dispatched
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(UpdateProfileProjectionMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

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

        // Expect NO message to be dispatched
        $this->messageBus
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

        // Expect only ONE message to be dispatched (deduplicated by pubkey)
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) use ($pubkey) {
                return $message instanceof UpdateProfileProjectionMessage
                    && $message->getPubkeyHex() === $pubkey;
            }))
            ->willReturn(new Envelope(new \stdClass()));

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

        // Expect TWO messages to be dispatched (one per unique pubkey with profile events)
        $this->messageBus
            ->expects($this->exactly(2))
            ->method('dispatch')
            ->with($this->isInstanceOf(UpdateProfileProjectionMessage::class))
            ->willReturn(new Envelope(new \stdClass()));

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
