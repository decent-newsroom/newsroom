<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Exception\BannedAuthorEvent;
use App\Repository\BannedPubkeyRepository;
use App\Service\Nostr\AuthorIngestionGate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AuthorIngestionGateTest extends TestCase
{
    public function testPermanentBanRejectsIngestion(): void
    {
        $bans = $this->createMock(BannedPubkeyRepository::class);
        $bans->method('isBanned')->with(str_repeat('a', 64))->willReturn(true);
        $this->expectException(BannedAuthorEvent::class);

        (new AuthorIngestionGate($bans, new NullLogger()))->assertAllowed(str_repeat('a', 64));
    }

    public function testNewBanIsVisibleAfterAnEarlierMissInTheSameWorker(): void
    {
        $hex = str_repeat('a', 64);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchOne')
            ->with('SELECT 1 FROM banned_pubkey WHERE pubkey = :pubkey', ['pubkey' => $hex])
            ->willReturnOnConsecutiveCalls(false, 1);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $repository = $this->getMockBuilder(BannedPubkeyRepository::class)
            ->disableOriginalConstructor()->onlyMethods(['getEntityManager'])->getMock();
        $repository->method('getEntityManager')->willReturn($em);
        $gate = new AuthorIngestionGate($repository, new NullLogger());
        $gate->assertAllowed(strtoupper($hex));

        $this->expectException(BannedAuthorEvent::class);
        $gate->assertAllowed($hex);
    }
}
