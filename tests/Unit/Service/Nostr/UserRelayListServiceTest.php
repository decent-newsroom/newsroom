<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Entity\UserRelayList;
use App\Enum\RelayPurpose;
use App\Repository\UserRelayListRepository;
use App\Service\Nostr\FollowsRelayPoolService;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for UserRelayListService — focuses on getRelaysForUser()
 * and the integration of the follows relay pool.
 */
class UserRelayListServiceTest extends TestCase
{
    private NostrRelayPool $relayPool;
    private RelayRegistry $relayRegistry;
    private UserRelayListRepository $relayListRepository;
    private EntityManagerInterface $em;
    private CacheItemPoolInterface $cache;
    private ?FollowsRelayPoolService $followsRelayPoolService;
    private UserRelayListService $service;

    protected function setUp(): void
    {
        $this->relayPool = $this->createMock(NostrRelayPool::class);
        $this->relayRegistry = new RelayRegistry(
            new NullLogger(),
            'ws://strfry:7777',
            ['wss://purplepag.es'],
            ['wss://relay.damus.io', 'wss://nos.lol'],
            ['wss://relay.decentnewsroom.com'],
            ['wss://relay.nsec.app'],
            [],
        );
        $this->relayListRepository = $this->createMock(UserRelayListRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->followsRelayPoolService = $this->createMock(FollowsRelayPoolService::class);

        $this->service = new UserRelayListService(
            $this->relayPool,
            $this->relayRegistry,
            $this->relayListRepository,
            $this->em,
            $this->cache,
            new NullLogger(),
            $this->followsRelayPoolService,
        );
    }

    // ------------------------------------------------------------------
    // getRelaysForUser — follows relay pool integration
    // ------------------------------------------------------------------

    public function testGetRelaysForUserContentIncludesFollowsPool(): void
    {
        $hex = str_repeat('a', 64);

        // User's own relay list from DB
        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.user.com']);
        $relayList->setWriteRelays(['wss://relay.user.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        // PSR-6 cache miss — forces DB lookup
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        // Follows pool returns relays from the user's follows
        $this->followsRelayPoolService->method('getPoolForUser')
            ->with($hex)
            ->willReturn(['wss://relay.follow1.com', 'wss://relay.follow2.com']);

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        // 1. Local relay first
        $this->assertSame('ws://strfry:7777', $result[0]);
        // 2. User's own relay
        $this->assertContains('wss://relay.user.com', $result);
        // 3. Follows pool relays
        $this->assertContains('wss://relay.follow1.com', $result);
        $this->assertContains('wss://relay.follow2.com', $result);
        // 4. Registry defaults
        $this->assertContains('wss://relay.damus.io', $result);
        $this->assertContains('wss://nos.lol', $result);
    }

    public function testGetRelaysForUserContentDeduplicatesFollowsPool(): void
    {
        $hex = str_repeat('b', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.damus.io']); // Already in registry defaults
        $relayList->setWriteRelays(['wss://relay.damus.io']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        // Follows pool returns a relay already in user list + one new
        $this->followsRelayPoolService->method('getPoolForUser')
            ->willReturn(['wss://relay.damus.io', 'wss://relay.unique-follow.com']);

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        // relay.damus.io should appear only once
        $damusCount = count(array_filter($result, fn($r) => $r === 'wss://relay.damus.io'));
        $this->assertSame(1, $damusCount, 'Duplicate relay should be deduplicated');

        // unique follow relay should be present
        $this->assertContains('wss://relay.unique-follow.com', $result);
    }

    public function testGetRelaysForUserProfileDoesNotIncludeFollowsPool(): void
    {
        $hex = str_repeat('c', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.user.com']);
        $relayList->setWriteRelays(['wss://relay.user.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        // Follows pool should NOT be called for PROFILE purpose
        $this->followsRelayPoolService->expects($this->never())
            ->method('getPoolForUser');

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::PROFILE);

        $this->assertSame('ws://strfry:7777', $result[0]);
        $this->assertContains('wss://relay.user.com', $result);
    }

    public function testGetRelaysForUserContentWorksWithoutFollowsService(): void
    {
        // Create service without FollowsRelayPoolService (null)
        $service = new UserRelayListService(
            $this->relayPool,
            $this->relayRegistry,
            $this->relayListRepository,
            $this->em,
            $this->cache,
            new NullLogger(),
            null, // no follows pool service
        );

        $hex = str_repeat('d', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.user.com']);
        $relayList->setWriteRelays(['wss://relay.user.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        $result = $service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        // Should still work fine — just without follows pool relays
        $this->assertSame('ws://strfry:7777', $result[0]);
        $this->assertContains('wss://relay.user.com', $result);
        $this->assertContains('wss://relay.damus.io', $result);
    }

    public function testGetRelaysForUserContentFollowsPoolExceptionIsSwallowed(): void
    {
        $hex = str_repeat('e', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.user.com']);
        $relayList->setWriteRelays(['wss://relay.user.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        // Follows pool throws an exception (e.g. Redis down)
        $this->followsRelayPoolService->method('getPoolForUser')
            ->willThrowException(new \RuntimeException('Redis connection failed'));

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        // Should still return relays, just without follows pool
        $this->assertSame('ws://strfry:7777', $result[0]);
        $this->assertContains('wss://relay.user.com', $result);
        $this->assertContains('wss://relay.damus.io', $result);
    }

    public function testGetRelaysForUserContentFollowsPoolSkipsProjectRelay(): void
    {
        $hex = str_repeat('f', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.user.com']);
        $relayList->setWriteRelays(['wss://relay.user.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        // Follows pool includes the project relay (which duplicates local)
        $this->followsRelayPoolService->method('getPoolForUser')
            ->willReturn(['wss://relay.decentnewsroom.com', 'wss://relay.newfollow.com']);

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        // Project relay should be skipped (local is already present)
        $this->assertNotContains('wss://relay.decentnewsroom.com', $result);
        // But the new follow relay should be included
        $this->assertContains('wss://relay.newfollow.com', $result);
    }

    public function testGetRelaysForUserContentFollowsPoolEmptyReturnsNormally(): void
    {
        $hex = str_repeat('a', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://relay.user.com']);
        $relayList->setWriteRelays(['wss://relay.user.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        // User has no follows (empty pool)
        $this->followsRelayPoolService->method('getPoolForUser')
            ->willReturn([]);

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        $this->assertSame('ws://strfry:7777', $result[0]);
        $this->assertContains('wss://relay.user.com', $result);
        $this->assertContains('wss://relay.damus.io', $result);
    }

    public function testGetRelaysForUserContentPriorityOrder(): void
    {
        $hex = str_repeat('a', 64);

        $relayList = new UserRelayList();
        $relayList->setPubkey($hex);
        $relayList->setReadRelays(['wss://user-relay.com']);
        $relayList->setWriteRelays(['wss://user-relay.com']);
        $relayList->setCreatedAt(time());

        $this->relayListRepository->method('findByPubkey')->willReturn($relayList);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $this->cache->method('getItem')->willReturn($cacheItem);
        $this->cache->method('save')->willReturn(true);

        $this->followsRelayPoolService->method('getPoolForUser')
            ->willReturn(['wss://follows-relay.com']);

        $result = $this->service->getRelaysForUser($hex, RelayPurpose::CONTENT);

        // Verify ordering: local → user → follows → registry
        $localIdx = array_search('ws://strfry:7777', $result);
        $userIdx = array_search('wss://user-relay.com', $result);
        $followsIdx = array_search('wss://follows-relay.com', $result);
        $registryIdx = array_search('wss://relay.damus.io', $result);

        $this->assertLessThan($userIdx, $localIdx, 'Local should come before user relays');
        $this->assertLessThan($followsIdx, $userIdx, 'User relays should come before follows pool');
        $this->assertLessThan($registryIdx, $followsIdx, 'Follows pool should come before registry defaults');
    }
}

