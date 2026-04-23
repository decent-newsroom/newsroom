<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Entity\Event;
use App\Entity\NotificationSubscription;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Enum\NotificationSourceTypeEnum;
use App\Repository\EventRepository;
use App\Repository\NotificationSubscriptionRepository;
use App\Service\Notification\NotificationMatcher;
use PHPUnit\Framework\TestCase;

class NotificationMatcherTest extends TestCase
{
    public function testShortCircuitsOnNonNotifiedKind(): void
    {
        $subRepo = $this->createMock(NotificationSubscriptionRepository::class);
        $subRepo->expects(self::never())->method('findActiveBySourceValues');
        $eventRepo = $this->createMock(EventRepository::class);

        $matcher = new NotificationMatcher($subRepo, $eventRepo);

        $event = $this->makeEvent(KindsEnum::REACTION->value, 'pubkey1');
        self::assertSame([], $matcher->match($event));
    }

    public function testMatchesLongformOnNpubSubscription(): void
    {
        $user = $this->makeUser(1);
        $sub = new NotificationSubscription($user, NotificationSourceTypeEnum::NPUB, 'pubkey1');

        $subRepo = $this->createMock(NotificationSubscriptionRepository::class);
        $subRepo->method('findActiveBySourceValues')
            ->willReturnCallback(function (NotificationSourceTypeEnum $type, array $values) use ($sub) {
                if ($type === NotificationSourceTypeEnum::NPUB && in_array('pubkey1', $values, true)) {
                    return [$sub];
                }
                return [];
            });
        $subRepo->method('findAllActiveGrouped')->willReturn(['npubs' => [], 'publications' => [], 'sets' => []]);

        $eventRepo = $this->createMock(EventRepository::class);

        $matcher = new NotificationMatcher($subRepo, $eventRepo);
        $event = $this->makeEvent(KindsEnum::LONGFORM->value, 'pubkey1', dTag: 'my-article');
        $matches = $matcher->match($event);
        self::assertCount(1, $matches);
        self::assertSame($sub, $matches[0]);
    }

    public function testDoesNotMatchOutOfScopeKindsForSubscribedNpub(): void
    {
        // Even if user is subscribed to pubkey1, kind 1, 7, 9735, 9802, 1111, 30041
        // must never produce notifications in v1.
        $user = $this->makeUser(1);
        $sub = new NotificationSubscription($user, NotificationSourceTypeEnum::NPUB, 'pubkey1');

        $subRepo = $this->createMock(NotificationSubscriptionRepository::class);
        $subRepo->method('findActiveBySourceValues')->willReturn([$sub]);
        $subRepo->method('findAllActiveGrouped')->willReturn(['npubs' => [], 'publications' => [], 'sets' => []]);
        $eventRepo = $this->createMock(EventRepository::class);

        $matcher = new NotificationMatcher($subRepo, $eventRepo);

        foreach ([
            KindsEnum::TEXT_NOTE->value,
            KindsEnum::REACTION->value,
            KindsEnum::ZAP_RECEIPT->value,
            KindsEnum::HIGHLIGHTS->value,
            KindsEnum::COMMENTS->value,
            KindsEnum::PUBLICATION_CONTENT->value,
        ] as $kind) {
            $event = $this->makeEvent($kind, 'pubkey1');
            self::assertSame([], $matcher->match($event), "kind $kind must not notify");
        }
    }

    public function testMatchesPublicationOnSelfCoordinate(): void
    {
        $user = $this->makeUser(1);
        $coord = '30040:pubkey1:my-mag';
        $sub = new NotificationSubscription($user, NotificationSourceTypeEnum::PUBLICATION, $coord);

        $subRepo = $this->createMock(NotificationSubscriptionRepository::class);
        $subRepo->method('findActiveBySourceValues')
            ->willReturnCallback(function (NotificationSourceTypeEnum $type, array $values) use ($sub, $coord) {
                if ($type === NotificationSourceTypeEnum::PUBLICATION && in_array($coord, $values, true)) {
                    return [$sub];
                }
                return [];
            });
        $subRepo->method('findAllActiveGrouped')->willReturn(['npubs' => [], 'publications' => [], 'sets' => []]);
        $eventRepo = $this->createMock(EventRepository::class);

        $matcher = new NotificationMatcher($subRepo, $eventRepo);
        $event = $this->makeEvent(KindsEnum::PUBLICATION_INDEX->value, 'pubkey1', dTag: 'my-mag');
        $matches = $matcher->match($event);
        self::assertCount(1, $matches);
    }

    public function testDedupsSameUserAcrossMultipleSubscriptions(): void
    {
        // The same user is subscribed via both NPUB and NIP51_SET to sources that
        // both match the same event. We must get exactly one subscription back.
        $user = $this->makeUser(42);
        $subNpub = new NotificationSubscription($user, NotificationSourceTypeEnum::NPUB, 'pubkey1');
        $subSet = new NotificationSubscription($user, NotificationSourceTypeEnum::NIP51_SET, '30000:pubkey2:my-set');

        $subRepo = $this->createMock(NotificationSubscriptionRepository::class);
        $subRepo->method('findActiveBySourceValues')->willReturnCallback(
            function (NotificationSourceTypeEnum $type) use ($subNpub, $subSet) {
                return match ($type) {
                    NotificationSourceTypeEnum::NPUB => [$subNpub],
                    NotificationSourceTypeEnum::NIP51_SET => [$subSet],
                    default => [],
                };
            }
        );
        $subRepo->method('findAllActiveGrouped')->willReturn([
            'npubs' => [], 'publications' => [], 'sets' => ['30000:pubkey2:my-set'],
        ]);

        // Expand the set to contain pubkey1 as a p-tag.
        $setEvent = new Event();
        $setEvent->setTags([['p', 'pubkey1'], ['d', 'my-set']]);
        $eventRepo = $this->createMock(EventRepository::class);
        $eventRepo->method('findByNaddr')->willReturn($setEvent);

        $matcher = new NotificationMatcher($subRepo, $eventRepo);
        $event = $this->makeEvent(KindsEnum::LONGFORM->value, 'pubkey1', dTag: 'hello');
        $matches = $matcher->match($event);
        self::assertCount(1, $matches, 'same user across multiple subscriptions must dedup');
    }

    // ---------------- helpers ----------------

    private function makeEvent(int $kind, string $pubkey, string $dTag = ''): Event
    {
        $e = new Event();
        $e->setId(str_repeat('a', 64));
        $e->setKind($kind);
        $e->setPubkey($pubkey);
        $e->setContent('');
        $e->setCreatedAt(time());
        $e->setTags([]);
        $e->setSig('');
        if ($kind >= 30000 && $kind <= 39999) {
            $e->setDTag($dTag);
        }
        return $e;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $user->setId($id);
        $user->setNpub('npub1testuser' . $id);
        return $user;
    }
}

