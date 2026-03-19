<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Nostr\FollowsRelayPoolService;
use App\Service\Nostr\RelayHealthStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FollowsRelayPoolServiceTest extends TestCase
{
    private \Redis $redis;
    private EventRepository $eventRepository;
    private RelayHealthStore $healthStore;
    private FollowsRelayPoolService $service;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(\Redis::class);
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->healthStore = $this->createMock(RelayHealthStore::class);

        $this->service = new FollowsRelayPoolService(
            $this->redis,
            $this->eventRepository,
            $this->healthStore,
            new NullLogger(),
        );
    }

    public function testGetPoolForUserReturnsEmptyWhenNoFollowsEvent(): void
    {
        $this->eventRepository->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->with('aabbcc', KindsEnum::FOLLOWS->value)
            ->willReturn(null);

        $result = $this->service->getPoolForUser('aabbcc');
        $this->assertSame([], $result);
    }

    public function testGetPoolForUserReturnsCachedWhenEventIdMatches(): void
    {
        $followsEvent = new Event();
        $followsEvent->setId('event123');
        $followsEvent->setKind(KindsEnum::FOLLOWS->value);
        $followsEvent->setPubkey('aabbcc');
        $followsEvent->setTags([['p', 'deadbeef']]);
        $followsEvent->setCreatedAt(time());
        $followsEvent->setSig('');
        $followsEvent->setContent('');

        $this->eventRepository->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->willReturn($followsEvent);

        $cachedPayload = json_encode([
            'relays' => ['wss://relay.example.com'],
            'follows_event_id' => 'event123',
            'built_at' => time(),
            'follows_count' => 1,
            'relay_list_coverage' => 1,
        ]);

        $this->redis->expects($this->once())
            ->method('get')
            ->with('follows_relay_pool:aabbcc')
            ->willReturn($cachedPayload);

        // Should not call findLatestRelayListsByPubkeys since cache is valid
        $this->eventRepository->expects($this->never())
            ->method('findLatestRelayListsByPubkeys');

        $result = $this->service->getPoolForUser('aabbcc');
        $this->assertSame(['wss://relay.example.com'], $result);
    }

    public function testGetPoolForUserRebuildsWhenEventIdChanges(): void
    {
        $followsEvent = new Event();
        $followsEvent->setId('new_event_456');
        $followsEvent->setKind(KindsEnum::FOLLOWS->value);
        $followsEvent->setPubkey('aabbcc');
        $followsEvent->setTags([
            ['p', str_repeat('a', 64)],
            ['p', str_repeat('b', 64)],
        ]);
        $followsEvent->setCreatedAt(time());
        $followsEvent->setSig('');
        $followsEvent->setContent('');

        $this->eventRepository->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->willReturn($followsEvent);

        // Stale cache with different event ID
        $stalePayload = json_encode([
            'relays' => ['wss://old-relay.example.com'],
            'follows_event_id' => 'old_event_123',
            'built_at' => time() - 3600,
            'follows_count' => 1,
            'relay_list_coverage' => 1,
        ]);

        $this->redis->expects($this->once())
            ->method('get')
            ->willReturn($stalePayload);

        // Batch relay list resolution
        $relayListEvent = new Event();
        $relayListEvent->setId('relay_list_1');
        $relayListEvent->setKind(10002);
        $relayListEvent->setPubkey(str_repeat('a', 64));
        $relayListEvent->setTags([
            ['r', 'wss://nos.lol'],
            ['r', 'wss://relay.damus.io', 'write'],
            ['r', 'wss://read-only.relay', 'read'],
        ]);
        $relayListEvent->setCreatedAt(time());
        $relayListEvent->setSig('');
        $relayListEvent->setContent('');

        $this->eventRepository->expects($this->once())
            ->method('findLatestRelayListsByPubkeys')
            ->willReturn([str_repeat('a', 64) => $relayListEvent]);

        // Health scores
        $this->healthStore->method('getHealthScore')
            ->willReturn(0.9);

        // Should write new cache
        $this->redis->expects($this->once())
            ->method('setex')
            ->with(
                'follows_relay_pool:aabbcc',
                2592000,
                $this->callback(function ($payload) {
                    $data = json_decode($payload, true);
                    $this->assertSame('new_event_456', $data['follows_event_id']);
                    // Should include wss://nos.lol (unmarked=both) and wss://relay.damus.io (write)
                    // but NOT wss://read-only.relay (read-only)
                    $this->assertContains('wss://nos.lol', $data['relays']);
                    $this->assertContains('wss://relay.damus.io', $data['relays']);
                    $this->assertNotContains('wss://read-only.relay', $data['relays']);
                    return true;
                })
            );

        $result = $this->service->getPoolForUser('aabbcc');

        $this->assertContains('wss://nos.lol', $result);
        $this->assertContains('wss://relay.damus.io', $result);
        $this->assertNotContains('wss://read-only.relay', $result);
    }

    public function testGetPoolForUserBuildsCacheMiss(): void
    {
        $followsEvent = new Event();
        $followsEvent->setId('first_build');
        $followsEvent->setKind(KindsEnum::FOLLOWS->value);
        $followsEvent->setPubkey('aabbcc');
        $followsEvent->setTags([['p', str_repeat('c', 64)]]);
        $followsEvent->setCreatedAt(time());
        $followsEvent->setSig('');
        $followsEvent->setContent('');

        $this->eventRepository->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->willReturn($followsEvent);

        // No cache
        $this->redis->expects($this->once())
            ->method('get')
            ->willReturn(false);

        // No relay lists found in DB
        $this->eventRepository->expects($this->once())
            ->method('findLatestRelayListsByPubkeys')
            ->willReturn([]);

        // Should still write the cache (empty pool)
        $this->redis->expects($this->once())
            ->method('setex');

        $result = $this->service->getPoolForUser('aabbcc');
        $this->assertSame([], $result);
    }

    public function testInvalidateDeletesRedisKey(): void
    {
        $this->redis->expects($this->once())
            ->method('del')
            ->with('follows_relay_pool:aabbcc');

        $this->service->invalidate('aabbcc');
    }

    public function testWarmPoolSkipsWhenCacheIsUpToDate(): void
    {
        $followsEvent = new Event();
        $followsEvent->setId('event123');
        $followsEvent->setKind(KindsEnum::FOLLOWS->value);
        $followsEvent->setPubkey('aabbcc');
        $followsEvent->setTags([]);
        $followsEvent->setCreatedAt(time());
        $followsEvent->setSig('');
        $followsEvent->setContent('');

        $this->eventRepository->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->willReturn($followsEvent);

        $this->redis->expects($this->once())
            ->method('get')
            ->willReturn(json_encode([
                'relays' => ['wss://relay.example.com'],
                'follows_event_id' => 'event123',
                'built_at' => time(),
                'follows_count' => 0,
                'relay_list_coverage' => 0,
            ]));

        // Should NOT rebuild (no batch query, no cache write)
        $this->eventRepository->expects($this->never())
            ->method('findLatestRelayListsByPubkeys');
        $this->redis->expects($this->never())
            ->method('setex');

        $this->service->warmPool('aabbcc');
    }

    public function testLocalhostRelaysAreFilteredOut(): void
    {
        $followsEvent = new Event();
        $followsEvent->setId('filter_test');
        $followsEvent->setKind(KindsEnum::FOLLOWS->value);
        $followsEvent->setPubkey('aabbcc');
        $followsEvent->setTags([['p', str_repeat('d', 64)]]);
        $followsEvent->setCreatedAt(time());
        $followsEvent->setSig('');
        $followsEvent->setContent('');

        $this->eventRepository->method('findLatestByPubkeyAndKind')
            ->willReturn($followsEvent);
        $this->redis->method('get')->willReturn(false);

        $relayListEvent = new Event();
        $relayListEvent->setId('rl_filter');
        $relayListEvent->setKind(10002);
        $relayListEvent->setPubkey(str_repeat('d', 64));
        $relayListEvent->setTags([
            ['r', 'wss://good.relay.com'],
            ['r', 'ws://localhost:7777'],
            ['r', 'wss://localhost:8080'],
            ['r', 'http://invalid.url'],
        ]);
        $relayListEvent->setCreatedAt(time());
        $relayListEvent->setSig('');
        $relayListEvent->setContent('');

        $this->eventRepository->method('findLatestRelayListsByPubkeys')
            ->willReturn([str_repeat('d', 64) => $relayListEvent]);
        $this->healthStore->method('getHealthScore')->willReturn(0.5);

        $this->redis->method('setex')->willReturn(true);

        $result = $this->service->getPoolForUser('aabbcc');
        $this->assertSame(['wss://good.relay.com'], $result);
    }
}

