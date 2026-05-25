<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Util\NostrKeyUtil;

/**
 * Builds a recent activity feed for current Essayist members.
 *
 * Activity kinds:
 * - highlights (kind 9802)
 * - reposts (kind 16)
 * - comments (kind 1111)
 */
final class EssayistMemberActivityService
{
    private const LOOKBACK_SECONDS = 604800; // 7 days
    private const MAX_MEMBERS_TO_SCAN = 1000;
    private const FETCH_LIMIT = 200;

    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly EventRepository $eventRepository,
    ) {
    }

    /**
     * @return array<int, array{event: Event, type: string}>
     */
    public function getRecentActivity(int $limit = 60): array
    {
        $members = $this->userRepository->findByRoleWithQuery(
            RolesEnum::ESSAYIST_MEMBER->value,
            null,
            self::MAX_MEMBERS_TO_SCAN,
        );

        $memberPubkeys = [];
        foreach ($members as $member) {
            $npub = (string) ($member->getNpub() ?? '');
            if ($npub === '' || !NostrKeyUtil::isNpub($npub)) {
                continue;
            }

            try {
                $memberPubkeys[] = NostrKeyUtil::npubToHex($npub);
            } catch (\Throwable) {
                // Skip malformed npubs silently.
            }
        }

        $memberPubkeys = array_values(array_unique($memberPubkeys));
        if ($memberPubkeys === []) {
            return [];
        }

        $events = $this->eventRepository->findByFilter([
            'kinds' => [
                KindsEnum::HIGHLIGHTS->value,
                KindsEnum::GENERIC_REPOST->value,
                KindsEnum::COMMENTS->value,
            ],
            'authors' => $memberPubkeys,
            'since' => time() - self::LOOKBACK_SECONDS,
            'limit' => self::FETCH_LIMIT,
        ]);

        $activity = [];
        foreach ($events as $event) {
            $type = $this->detectActivityType($event);
            if ($type === null) {
                continue;
            }

            $activity[] = [
                'event' => $event,
                'type' => $type,
            ];

            if (count($activity) >= $limit) {
                break;
            }
        }

        return $activity;
    }

    private function detectActivityType(Event $event): ?string
    {
        return match ($event->getKind()) {
            KindsEnum::HIGHLIGHTS->value => 'highlight',
            KindsEnum::GENERIC_REPOST->value => 'repost',
            KindsEnum::COMMENTS->value => 'comment',
            default => null,
        };
    }
}


