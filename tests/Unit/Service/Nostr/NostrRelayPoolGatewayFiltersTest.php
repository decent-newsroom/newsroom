<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayGatewayClient;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;

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
                8
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

        $pool->sendToRelays(
            ['wss://relay.example'],
            function (): RequestMessage {
                $subscription = new Subscription();
                $subscriptionId = $subscription->setId();

                $upper = new Filter();
                $upper->setKinds([1111, 9735]);
                $upper->setTag('#A', ['30023:pubkey:slug']);

                $lower = new Filter();
                $lower->setKinds([1111, 9735]);
                $lower->setTag('#a', ['30023:pubkey:slug']);

                return new RequestMessage($subscriptionId, [$upper, $lower]);
            },
            10,
            null,
            null
        );
    }
}

