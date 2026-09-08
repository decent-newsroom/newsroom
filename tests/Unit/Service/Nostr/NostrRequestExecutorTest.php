<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelayPoolInterface;
use App\Service\Nostr\RelaySet;
use App\Service\Nostr\RelaySetFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NostrRequestExecutorTest extends TestCase
{
    public function testBuildRequestNormalizesTagFilters(): void
    {
        $relaySet = new RelaySet();
        $relaySetFactory = $this->createMock(RelaySetFactory::class);
        $relaySetFactory->expects(self::once())
            ->method('getDefault')
            ->willReturn($relaySet);

        $executor = new NostrRequestExecutor(
            $this->createMock(RelayPoolInterface::class),
            $relaySetFactory,
            $this->createMock(LoggerInterface::class),
        );

        $request = $executor->buildRequest([30023], ['a' => ['30023:pubkey:slug']]);

        self::assertSame(
            [['kinds' => [30023], '#a' => ['30023:pubkey:slug']]],
            $request->getFilters(),
        );
    }
}
