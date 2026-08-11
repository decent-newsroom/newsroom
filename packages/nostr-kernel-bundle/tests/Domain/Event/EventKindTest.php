<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use PHPUnit\Framework\TestCase;

final class EventKindTest extends TestCase
{
    public function testKindZeroIsReplaceable(): void
    {
        self::assertTrue((new EventKind(0))->isReplaceable());
    }

    public function testKindThreeIsReplaceable(): void
    {
        self::assertTrue((new EventKind(3))->isReplaceable());
    }

    public function testKindOneZeroZeroZeroTwoIsReplaceableAndRelayList(): void
    {
        $kind = new EventKind(10002);

        self::assertTrue($kind->isReplaceable());
        self::assertTrue($kind->isRelayList());
    }

    public function testKindTwoZeroZeroZeroZeroIsEphemeral(): void
    {
        self::assertTrue((new EventKind(20000))->isEphemeral());
    }

    public function testKindThreeZeroZeroTwoThreeIsAddressableAndLongForm(): void
    {
        $kind = new EventKind(30023);

        self::assertTrue($kind->isAddressable());
        self::assertTrue($kind->isLongFormArticle());
    }

    public function testKindNineSevenThreeFiveIsZapReceipt(): void
    {
        self::assertTrue((new EventKind(9735))->isZapReceipt());
    }

    public function testKindTwoSevenTwoThreeFiveIsHttpAuth(): void
    {
        self::assertTrue((new EventKind(27235))->isHttpAuth());
    }
}

