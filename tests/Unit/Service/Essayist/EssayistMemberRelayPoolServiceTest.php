<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Essayist;

use App\Entity\User;
use App\Entity\UserRelayList;
use App\Repository\UserEntityRepository;
use App\Repository\UserRelayListRepository;
use App\Service\Essayist\EssayistMemberRelayPoolService;
use App\Service\Nostr\RelayHealthStore;
use App\Util\NostrKeyUtil;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EssayistMemberRelayPoolServiceTest extends TestCase
{
    public function testReturnsCachedPoolWhenPresent(): void
    {
        $redis = $this->createMock(\Redis::class);
        $userRepository = $this->createMock(UserEntityRepository::class);
        $relayListRepository = $this->createMock(UserRelayListRepository::class);
        $healthStore = $this->createMock(RelayHealthStore::class);

        $redis->expects($this->once())
            ->method('get')
            ->with('essayist_member_relay_pool:v1')
            ->willReturn(json_encode([
                'relays' => ['wss://relay.one', 'wss://relay.two'],
                'built_at' => time(),
            ]));

        $userRepository->expects($this->never())->method('findByRoleWithQuery');

        $service = new EssayistMemberRelayPoolService(
            $redis,
            $userRepository,
            $relayListRepository,
            $healthStore,
            new NullLogger(),
        );

        $this->assertSame(['wss://relay.one', 'wss://relay.two'], $service->getRelayUrls());
    }

    public function testBuildsDeduplicatedAndFilteredPoolFromMembers(): void
    {
        $redis = $this->createMock(\Redis::class);
        $userRepository = $this->createMock(UserEntityRepository::class);
        $relayListRepository = $this->createMock(UserRelayListRepository::class);
        $healthStore = $this->createMock(RelayHealthStore::class);

        $memberHexA = str_repeat('a1', 32);
        $memberHexB = str_repeat('b2', 32);

        $memberA = new User();
        $memberA->setNpub(NostrKeyUtil::hexToNpub($memberHexA));

        $memberB = new User();
        $memberB->setNpub(NostrKeyUtil::hexToNpub($memberHexB));

        $userRepository->expects($this->once())
            ->method('findByRoleWithQuery')
            ->willReturn([$memberA, $memberB]);

        $listA = new UserRelayList();
        $listA->setPubkey($memberHexA);
        $listA->setWriteRelays([
            'WSS://Relay.One/',
            'wss://relay.two',
            'ws://localhost:7777',
        ]);

        $listB = new UserRelayList();
        $listB->setPubkey($memberHexB);
        $listB->setWriteRelays([
            'wss://relay.two',
            'wss://relay.three',
            'https://not-a-relay.example.com',
        ]);

        $relayListRepository->expects($this->once())
            ->method('findByPubkeys')
            ->willReturn([
                $memberHexA => $listA,
                $memberHexB => $listB,
            ]);

        $healthStore->method('getHealthScore')
            ->willReturnCallback(static function (string $relay): float {
                return match ($relay) {
                    'wss://relay.three' => 0.9,
                    'wss://relay.one' => 0.7,
                    default => 0.3,
                };
            });

        $redis->expects($this->once())
            ->method('setex')
            ->with(
                'essayist_member_relay_pool:v1',
                21600,
                $this->callback(function (string $payload): bool {
                    $decoded = json_decode($payload, true);
                    $this->assertIsArray($decoded['relays'] ?? null);
                    return true;
                })
            );

        // Cache miss first.
        $redis->expects($this->once())->method('get')->willReturn(false);

        $service = new EssayistMemberRelayPoolService(
            $redis,
            $userRepository,
            $relayListRepository,
            $healthStore,
            new NullLogger(),
        );

        $pool = $service->getRelayUrls();

        $this->assertSame(
            ['wss://relay.three', 'wss://relay.one', 'wss://relay.two'],
            $pool
        );
    }
}

