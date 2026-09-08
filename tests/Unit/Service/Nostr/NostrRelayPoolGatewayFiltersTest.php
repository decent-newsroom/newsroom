<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayGatewayClient;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayEndpoint;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\RelaySet;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NostrRelayPoolGatewayFiltersTest extends TestCase
{
    public function testGatewayReceivesAllReqFilters(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $relayRegistry = $this->createMock(RelayRegistry::class);
        $healthStore = $this->createMock(RelayHealthStore::class);
        $gatewayClient = $this->createMock(RelayGatewayClient::class);

        $relayRegistry->method('getDefaultRelays')->willReturn([]);
        $relayRegistry->method('getProjectRelay')->willReturn(null);
        $healthStore->method('isMuted')->willReturn(false);

        $gatewayClient->expects($this->once())
            ->method('query')
            ->with(
                ['wss://relay.example'],
                $this->callback(function (array $filters): bool {
                    if (count($filters) !== 2) {
                        return false;
                    }

                    $first = $filters[0] ?? [];
                    $second = $filters[1] ?? [];

                    return isset($first['#A'], $second['#a'])
                        && ($first['#A'][0] ?? null) === '30023:pubkey:slug'
                        && ($second['#a'][0] ?? null) === '30023:pubkey:slug';
                }),
                null,
                11
            )
            ->willReturn(['events' => [], 'errors' => []]);

        $pool = new NostrRelayPool(
            $logger,
            $relayRegistry,
            $healthStore,
            'wss://local.example',
            true,
            $gatewayClient,
            []
        );

        $request = new RelayQueryRequest(
            new RelaySet([new RelayEndpoint('wss://relay.example')]),
            [
                ['kinds' => [1111, 9735], '#A' => ['30023:pubkey:slug']],
                ['kinds' => [1111, 9735], '#a' => ['30023:pubkey:slug']],
            ],
        );
        $request->setTimeout(10);
        $request->setGatewayTimeout(11);

        $pool->executeRequest($request);
    }
}
