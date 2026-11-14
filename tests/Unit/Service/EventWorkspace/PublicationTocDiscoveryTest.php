<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Message\FetchEventFromRelaysMessage;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\PublicationTocDiscovery;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class PublicationTocDiscoveryTest extends TestCase
{
    /** @dataProvider reservations */
    public function testAtomicReservationBoundsAndDerivesMessage(array $reservation, string $status, bool $queued): void
    {
        $reader = str_repeat('a', 64);
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(30040);
        $event->setPubkey($reader);
        $event->setCreatedAt(123);
        $event->setContent('');
        $event->setTags([['d', 'book'], ['a', '30041:' . $reader . ':chapter:one', 'https://evil.example']]);
        $reference = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedEvent($event)->references[0];
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('eval')->willReturnCallback(
            static function (string $script, array $keys, int $number) use ($reservation, $reader): array {
                self::assertSame(3, $number);
                self::assertCount(3, $keys);
                self::assertStringContainsString("redis.call('INCR', KEYS[2])", $script);
                self::assertStringContainsString('>= 2', $script);
                self::assertStringContainsString('>= 4', $script);
                self::assertStringNotContainsString($reader, implode(',', $keys));
                self::assertStringNotContainsString('chapter:one', implode(',', $keys));
                return $reservation;
            },
        );
        $relays = $this->createMock(RelayRegistry::class);
        $relays->method('getContentRelays')->willReturn(['wss://1.example', 'wss://2.example', 'wss://3.example', 'wss://4.example', 'wss://5.example']);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($queued ? self::once() : self::never())->method('dispatch')
            ->willReturnCallback(static function (FetchEventFromRelaysMessage $message) use ($reader): Envelope {
                self::assertSame('naddr', $message->type);
                self::assertSame(30041, $message->kind);
                self::assertSame($reader, $message->pubkey);
                self::assertSame('chapter:one', $message->identifier);
                self::assertNull($message->exactVersionEventId);
                self::assertTrue($message->workspaceDiscovery);
                self::assertNull($message->eventId);
                self::assertCount(4, $message->relays);
                self::assertNotContains('https://evil.example', $message->relays);
                return new Envelope($message);
            });
        $result = (new PublicationTocDiscovery($redis, $bus, $relays))->request($reader, $reference);
        self::assertSame($status, $result['status']);
        self::assertSame($reservation[1], $result['retryAfter']);
    }

    public function testColdExactPinIsUnavailableWithoutQueueingOrRedisReservation(): void
    {
        $reader = str_repeat('a', 64);
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(30040);
        $event->setPubkey($reader);
        $event->setCreatedAt(123);
        $event->setContent('');
        $event->setTags([['d', 'book'], ['a', '30041:' . $reader . ':chapter', '', str_repeat('c', 64)]]);
        $reference = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedEvent($event)->references[0];
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('eval');
        $redis->expects(self::never())->method('ttl');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $discovery = new PublicationTocDiscovery($redis, $bus, $this->createMock(RelayRegistry::class));
        self::assertSame(['status' => 'unavailable', 'retryAfter' => 0], $discovery->request($reader, $reference));
        self::assertSame(['status' => 'unavailable', 'retryAfter' => 0], $discovery->status($reader, $reference));
    }

    public static function reservations(): iterable
    {
        yield 'new' => [[1, 60], 'loading', true];
        yield 'duplicate or in-flight retry' => [[0, 40], 'loading', false];
        yield 'attempt/budget exhausted' => [[-1, 500], 'limited', false];
    }

    public function testRedisFailureCannotQueue(): void
    {
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(30040);
        $event->setPubkey(str_repeat('a', 64));
        $event->setCreatedAt(123);
        $event->setContent('');
        $event->setTags([['d', 'book'], ['a', '30040:' . str_repeat('c', 64) . ':']]);
        $reference = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedEvent($event)->references[0];
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willReturn(false);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $this->expectException(\RuntimeException::class);
        (new PublicationTocDiscovery($redis, $bus, $this->createMock(RelayRegistry::class)))
            ->request(str_repeat('a', 64), $reference);
    }
}
