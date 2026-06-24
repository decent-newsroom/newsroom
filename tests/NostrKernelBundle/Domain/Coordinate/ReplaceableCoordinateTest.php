<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Domain\Coordinate;

use DecentNewsroom\NostrKernelBundle\Domain\Coordinate\ReplaceableCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidCoordinate;
use PHPUnit\Framework\TestCase;

final class ReplaceableCoordinateTest extends TestCase
{
    private const PUBKEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testItBuildsReplaceableCoordinateString(): void
    {
        $coordinate = new ReplaceableCoordinate(new EventKind(10002), new Pubkey(self::PUBKEY));

        self::assertSame('10002:' . self::PUBKEY, $coordinate->toString());
    }

    public function testItAllowsAddressableKindsButStringRemainsReplaceableForm(): void
    {
        $coordinate = new ReplaceableCoordinate(new EventKind(30023), new Pubkey(self::PUBKEY));

        self::assertSame('30023:' . self::PUBKEY, $coordinate->toString());
    }

    public function testItThrowsWhenKindIsNeitherReplaceableNorAddressable(): void
    {
        $this->expectException(InvalidCoordinate::class);

        new ReplaceableCoordinate(new EventKind(9735), new Pubkey(self::PUBKEY));
    }
}

