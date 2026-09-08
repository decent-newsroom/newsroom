<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelayEndpoint;
use App\Service\Nostr\RelaySet;
use App\Service\Nostr\RelaySetFactory;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\SocialEventService;
use App\Service\Nostr\UserRelayListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SocialEventServiceTest extends TestCase
{
    public function testGetCommentsFansOutToLocalAuthorAndDefaultRelays(): void
    {
        $executor = $this->createMock(NostrRequestExecutor::class);
        $relaySetFactory = $this->createMock(RelaySetFactory::class);
        $relayPool = $this->createMock(NostrRelayPool::class);
        $logger = $this->createMock(LoggerInterface::class);
        $userRelayListService = $this->createMock(UserRelayListService::class);

        $pubkey = str_repeat('a', 64);
        $coordinate = '30023:' . $pubkey . ':article-slug';

        $userRelayListService->expects($this->once())
            ->method('getRelaysForAuthorContent')
            ->with($pubkey, 5)
            ->willReturn([
                'wss://author-1.example',
                'wss://default.example',
            ]);

        $relayPool->expects($this->once())
            ->method('getDefaultRelays')
            ->willReturn([
                'wss://default.example',
                'wss://default-2.example',
            ]);

        $relaySetFactory->expects($this->once())
            ->method('fromUrls')
            ->with([
                'wss://local.example',
                'wss://author-1.example',
                'wss://default.example',
                'wss://default-2.example',
            ])
            ->willReturn(new RelaySet([
                new RelayEndpoint('wss://local.example'),
                new RelayEndpoint('wss://author-1.example'),
                new RelayEndpoint('wss://default.example'),
                new RelayEndpoint('wss://default-2.example'),
            ]));

        $executor->expects($this->once())
            ->method('execute')
            ->with($this->isInstanceOf(RelayQueryRequest::class))
            ->willReturnCallback(function (RelayQueryRequest $request) use ($coordinate): array {
                self::assertCount(2, $request->getFilters());
                self::assertSame([$coordinate], $request->getFilters()[0]['#A']);
                self::assertSame([$coordinate], $request->getFilters()[1]['#a']);
                self::assertIsArray($request->getFilters()[0]);
                return [];
            });

        $executor->expects($this->once())
            ->method('process')
            ->with([], $this->isType('callable'));

        $service = new SocialEventService(
            $executor,
            $relaySetFactory,
            $relayPool,
            $logger,
            'wss://local.example',
            $userRelayListService,
        );

        self::assertSame([], $service->getComments($coordinate, null, $pubkey));
    }
}
