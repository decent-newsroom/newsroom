<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventNormalizerInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use App\Service\Nostr\NostrEventIngressGuard;
use PHPUnit\Framework\TestCase;

final class NostrEventIngressGuardTest extends TestCase
{
    public function testItNormalizesLegacyObjectsThroughTheBundle(): void
    {
        $expected = new NostrEvent(
            kind: new EventKind(1),
            pubkey: new Pubkey(str_repeat('a', 64)),
            tags: EventTags::fromRaw([]),
        );
        $normalizer = $this->createMock(EventNormalizerInterface::class);
        $normalizer->expects(self::once())
            ->method('normalize')
            ->with([
                'id' => null,
                'pubkey' => str_repeat('a', 64),
                'created_at' => 1,
                'kind' => 1,
                'tags' => [],
                'content' => '',
                'sig' => null,
            ])
            ->willReturn($expected);

        $event = new class {
            public function toArray(): array
            {
                return [
                    'id' => null,
                    'pubkey' => str_repeat('a', 64),
                    'created_at' => 1,
                    'kind' => 1,
                    'tags' => [],
                    'content' => '',
                    'sig' => null,
                ];
            }
        };

        $guard = new NostrEventIngressGuard($normalizer);

        self::assertSame($expected, $guard->normalizeObject($event));
    }
}
