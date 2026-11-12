<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\UserMuteListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UserMuteListServiceTest extends TestCase
{
    public function testStrictReadReturnsDeduplicatedLowercaseValidPubkeys(): void
    {
        $reader = str_repeat('a', 64);
        $muted = str_repeat('b', 64);
        $event = new Event();
        $event->setTags([
            ['p', strtoupper($muted)],
            ['p', $muted],
            ['p', 'not-a-pubkey'],
            ['e', str_repeat('c', 64)],
            'malformed',
        ]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestByPubkeyAndKind')
            ->with($reader, KindsEnum::MUTE_LIST->value)
            ->willReturn($event);

        self::assertSame([$muted], (new UserMuteListService($repository, new NullLogger()))->getMutedPubkeysStrict($reader));
    }

    public function testStrictReadPropagatesPolicyLookupFailure(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestByPubkeyAndKind')
            ->willThrowException(new \RuntimeException('mute-list lookup unavailable'));

        $this->expectException(\RuntimeException::class);
        (new UserMuteListService($repository, new NullLogger()))
            ->getMutedPubkeysStrict(str_repeat('a', 64));
    }

    public function testReturnsEmptyWhenNoMuteListEvent(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->method('findLatestByPubkeyAndKind')
            ->with('aabbcc', KindsEnum::MUTE_LIST->value)
            ->willReturn(null);

        $service = new UserMuteListService($repo, new NullLogger());

        self::assertSame([], $service->getMutedPubkeys('aabbcc'));
    }

    public function testExtractsMutedPubkeysFromPTags(): void
    {
        $event = new Event();
        $event->setTags([
            ['p', 'muted1'],
            ['p', 'muted2'],
            ['t', 'spam'],       // Not a 'p' tag — should be ignored
            ['e', 'someeventid'], // Not a 'p' tag — should be ignored
        ]);

        $repo = $this->createMock(EventRepository::class);
        $repo->method('findLatestByPubkeyAndKind')
            ->with('aabbcc', KindsEnum::MUTE_LIST->value)
            ->willReturn($event);

        $service = new UserMuteListService($repo, new NullLogger());

        $result = $service->getMutedPubkeys('aabbcc');
        self::assertSame(['muted1', 'muted2'], $result);
    }

    public function testDeduplicatesMutedPubkeys(): void
    {
        $event = new Event();
        $event->setTags([
            ['p', 'muted1'],
            ['p', 'muted1'], // Duplicate
            ['p', 'muted2'],
        ]);

        $repo = $this->createMock(EventRepository::class);
        $repo->method('findLatestByPubkeyAndKind')
            ->willReturn($event);

        $service = new UserMuteListService($repo, new NullLogger());

        $result = $service->getMutedPubkeys('aabbcc');
        self::assertSame(['muted1', 'muted2'], $result);
    }

    public function testReturnsEmptyOnException(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->method('findLatestByPubkeyAndKind')
            ->willThrowException(new \RuntimeException('DB error'));

        $service = new UserMuteListService($repo, new NullLogger());

        self::assertSame([], $service->getMutedPubkeys('aabbcc'));
    }
}
