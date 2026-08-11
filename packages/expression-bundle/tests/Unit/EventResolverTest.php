<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Tests\Unit;

use DecentNewsroom\ExpressionBundle\Contract\RelayEventClientInterface;
use DecentNewsroom\ExpressionBundle\Contract\RelaySelectorInterface;
use DecentNewsroom\ExpressionBundle\Model\ArrayEvent;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EventResolverTest extends TestCase
{
    public function testFindByIdUsesRelaysWithoutLocalEventStore(): void
    {
        $event = new ArrayEvent(
            id: str_repeat('ab', 32),
            kind: 1,
            pubkey: str_repeat('cd', 32),
            content: 'relay event',
            createdAt: 1_700_000_000,
            tags: [],
        );

        $relayClient = $this->createMock(RelayEventClientInterface::class);
        $relayClient
            ->expects($this->once())
            ->method('fetch')
            ->with(
                [],
                ['ids' => [$event->getId()], 'limit' => 1],
                ['wss://relay.example'],
                null,
            )
            ->willReturn([$event]);

        $relaySelector = $this->relaySelector();

        $resolver = new EventResolver($relayClient, $relaySelector, null, new NullLogger());

        $this->assertSame($event, $resolver->findById($event->getId()));
    }

    private function relaySelector(): RelaySelectorInterface
    {
        $selector = $this->createMock(RelaySelectorInterface::class);
        $selector->method('ensureLocalRelay')->willReturn(['wss://relay.example']);
        $selector->method('getDefaultRelays')->willReturn([]);
        $selector->method('getContentRelays')->willReturn([]);
        $selector->method('getAuthorRelays')->willReturn([]);
        $selector->method('canonicalize')->willReturnArgument(0);

        return $selector;
    }
}
