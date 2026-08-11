<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Domain\Coordinate;

use DecentNewsroom\NostrKernelBundle\Domain\Coordinate\AddressableCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidCoordinate;
use PHPUnit\Framework\TestCase;

final class AddressableCoordinateTest extends TestCase
{
    private const PUBKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testItBuildsAddressableCoordinateString(): void
    {
        $coordinate = new AddressableCoordinate(new EventKind(30023), new Pubkey(self::PUBKEY), 'story');

        self::assertSame('30023:' . self::PUBKEY . ':story', $coordinate->toString());
    }

    public function testItThrowsWhenKindIsNotAddressable(): void
    {
        $this->expectException(InvalidCoordinate::class);

        new AddressableCoordinate(new EventKind(10002), new Pubkey(self::PUBKEY), 'story');
    }

    public function testItThrowsWhenDTagIsEmpty(): void
    {
        $this->expectException(InvalidCoordinate::class);

        new AddressableCoordinate(new EventKind(30023), new Pubkey(self::PUBKEY), '');
    }
}

