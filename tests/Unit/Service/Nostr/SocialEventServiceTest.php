<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelaySetFactory;
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

        $relayPool->expects($this->once())
            ->method('sendToRelays')
            ->with(
                [
                    'wss://local.example',
                    'wss://author-1.example',
                    'wss://default.example',
                    'wss://default-2.example',
                ],
                $this->isType('callable'),
                10,
                $this->isType('string')
            )
            ->willReturn([]);

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

