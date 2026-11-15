<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Essayist;

use App\Entity\Event;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\Essayist\EssayistMemberActivityService;
use App\Service\Nostr\NostrLinkParser;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EssayistMemberActivityServiceTest extends TestCase
{
    public function testReturnsEmptyWhenNoMembers(): void
    {
        $userRepository = $this->createMock(UserEntityRepository::class);
        $eventRepository = $this->createMock(EventRepository::class);

        $userRepository->expects($this->once())
            ->method('findByRoleWithQuery')
            ->willReturn([]);

        $eventRepository->expects($this->never())->method('findByFilter');

        $service = new EssayistMemberActivityService(
            $userRepository,
            $eventRepository,
            new NostrLinkParser(new NullLogger()),
        );

        $this->assertSame([], $service->getRecentActivity());
    }

    public function testIncludesOnlyRequestedActivityKindsAndClassifiesTypes(): void
    {
        $userRepository = $this->createMock(UserEntityRepository::class);
        $eventRepository = $this->createMock(EventRepository::class);

        $memberHex = str_repeat('a1', 32);
        $member = new User();
        $member->setNpub((static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($memberHex)));

        $userRepository->expects($this->once())
            ->method('findByRoleWithQuery')
            ->willReturn([$member]);

        $events = [
            $this->makeEvent('evt-1', KindsEnum::TEXT_NOTE->value, $memberHex, 'plain note', []),
            $this->makeEvent('evt-2', KindsEnum::REPOST->value, $memberHex, '', [['e', 'abc']]),
            $this->makeEvent('evt-3', KindsEnum::GENERIC_REPOST->value, $memberHex, '', [['e', 'def']]),
            $this->makeEvent('evt-4', KindsEnum::HIGHLIGHTS->value, $memberHex, 'highlight', [['a', '30023:' . $memberHex . ':slug']]),
            $this->makeEvent('evt-5', KindsEnum::COMMENTS->value, $memberHex, 'comment', [['a', '30023:' . $memberHex . ':slug']]),
        ];

        $eventRepository->expects($this->once())
            ->method('findByFilter')
            ->with($this->callback(function (array $filter) use ($memberHex): bool {
                $this->assertContains(KindsEnum::HIGHLIGHTS->value, $filter['kinds'] ?? []);
                $this->assertContains(KindsEnum::GENERIC_REPOST->value, $filter['kinds'] ?? []);
                $this->assertContains(KindsEnum::COMMENTS->value, $filter['kinds'] ?? []);
                $this->assertNotContains(KindsEnum::REPOST->value, $filter['kinds'] ?? []);
                $this->assertNotContains(KindsEnum::TEXT_NOTE->value, $filter['kinds'] ?? []);
                $this->assertSame([$memberHex], $filter['authors'] ?? []);
                return true;
            }))
            ->willReturn($events);

        $service = new EssayistMemberActivityService(
            $userRepository,
            $eventRepository,
            new NostrLinkParser(new NullLogger()),
        );

        $activity = $service->getRecentActivity();

        $this->assertCount(3, $activity);
        $this->assertSame(['repost', 'highlight', 'comment'], array_column($activity, 'type'));
    }

    public function testHighlightPreservesAnExactNoteSourceReference(): void
    {
        $memberHex = str_repeat('a1', 32);
        $noteId = str_repeat('b', 64);
        $member = new User();
        $member->setNpub((static function (string $pubkey): string {
            return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32()
                ?? throw new \InvalidArgumentException('Not a valid hex pubkey');
        })($memberHex));
        $userRepository = $this->createMock(UserEntityRepository::class);
        $userRepository->method('findByRoleWithQuery')->willReturn([$member]);
        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->method('findByFilter')->willReturn([
            $this->makeEvent(
                'highlight-id',
                KindsEnum::HIGHLIGHTS->value,
                $memberHex,
                'A highlighted passage',
                [
                    ['context', 'The passage'],
                    ['E', $noteId, 'wss://wrong.example'],
                    ['e', strtoupper($noteId), 'wss://relay.example', 'mention'],
                    ['a', '30023:' . $memberHex . ':source-article'],
                ],
            ),
        ]);
        $service = new EssayistMemberActivityService(
            $userRepository,
            $eventRepository,
            new NostrLinkParser(new NullLogger()),
        );

        $activity = $service->getRecentActivity();

        self::assertCount(1, $activity);
        self::assertSame('highlight', $activity[0]['type']);
        self::assertSame(strtolower($noteId), $activity[0]['highlight']['event_ref']);
        self::assertSame('30023:' . $memberHex . ':source-article', $activity[0]['highlight']['article_ref']);
    }

    public function testHighlightDoesNotTreatUppercaseEventTagAsANip84Source(): void
    {
        $memberHex = str_repeat('a1', 32);
        $noteId = str_repeat('b', 64);
        $member = new User();
        $member->setNpub((static function (string $pubkey): string {
            return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32()
                ?? throw new \InvalidArgumentException('Not a valid hex pubkey');
        })($memberHex));
        $userRepository = $this->createMock(UserEntityRepository::class);
        $userRepository->method('findByRoleWithQuery')->willReturn([$member]);
        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->method('findByFilter')->willReturn([
            $this->makeEvent(
                'highlight-id',
                KindsEnum::HIGHLIGHTS->value,
                $memberHex,
                'A highlighted passage',
                [['E', $noteId]],
            ),
        ]);
        $service = new EssayistMemberActivityService(
            $userRepository,
            $eventRepository,
            new NostrLinkParser(new NullLogger()),
        );

        $activity = $service->getRecentActivity();

        self::assertCount(1, $activity);
        self::assertNull($activity[0]['highlight']['event_ref']);
    }

    private function makeEvent(string $id, int $kind, string $pubkey, string $content, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setContent($content);
        $event->setCreatedAt(time());
        $event->setTags($tags);
        $event->setSig('');

        return $event;
    }
}
