<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Application\Event;

use DecentNewsroom\NostrKernelBundle\Application\Event\ResolveEventCoordinate;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use PHPUnit\Framework\TestCase;

final class ResolveEventCoordinateTest extends TestCase
{
    private const PUBKEY = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testAddressableKindWithDTagResolvesAddressableCoordinate(): void
    {
        $resolver = new ResolveEventCoordinate();
        $event = $this->event(30023, [['d', 'my-article']]);

        $coordinate = $resolver->resolve($event);

        self::assertNotNull($coordinate);
        self::assertSame('30023:' . self::PUBKEY . ':my-article', $coordinate->toString());
    }

    public function testAddressableKindWithoutDTagReturnsNull(): void
    {
        $resolver = new ResolveEventCoordinate();
        $event = $this->event(30023, []);

        self::assertNull($resolver->resolve($event));
    }

    public function testReplaceableNonAddressableKindResolvesReplaceableCoordinate(): void
    {
        $resolver = new ResolveEventCoordinate();
        $event = $this->event(10002, []);

        $coordinate = $resolver->resolve($event);

        self::assertNotNull($coordinate);
        self::assertSame('10002:' . self::PUBKEY, $coordinate->toString());
    }

    /**
     * @param list<list<mixed>> $tags
     */
    private function event(int $kind, array $tags): NostrEvent
    {
        return new NostrEvent(
            kind: new EventKind($kind),
            pubkey: new Pubkey(self::PUBKEY),
            tags: EventTags::fromRaw($tags),
        );
    }
}

