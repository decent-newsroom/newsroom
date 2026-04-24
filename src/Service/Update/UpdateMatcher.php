<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\EventRepository;
use App\Repository\UpdateSubscriptionRepository;

/**
 * Resolves the set of {@see UpdateSubscription} rows that should receive
 * an update for a given ingested {@see Event}.
 *
 * v1 notified kinds: {@see self::NOTIFIED_KINDS}. Events of any other kind
 * short-circuit and never produce updates.
 */
class UpdateMatcher
{
    public const NOTIFIED_KINDS = [
        KindsEnum::LONGFORM->value,          // 30023
        KindsEnum::PUBLICATION_INDEX->value, // 30040
    ];

    /**
     * NIP-51 set kinds a user may subscribe to. The value of an NPUB/PUBLICATION
     * match contained in such a set gets folded into the matching pass.
     */
    private const SET_KINDS = [
        KindsEnum::FOLLOWS->value,        // 3
        KindsEnum::INTERESTS->value,      // 10015
        KindsEnum::MUTE_LIST->value,      // 10000 (subscribable for awareness, though unusual)
        KindsEnum::BOOKMARK_SETS->value,  // 30003
        KindsEnum::CURATION_SET->value,   // 30004
        KindsEnum::CURATION_VIDEOS->value,// 30005
        KindsEnum::INTEREST_SETS->value,  // 30015
        KindsEnum::FOLLOW_PACK->value,    // 39089
    ];

    public function __construct(
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly EventRepository $eventRepository,
    ) {
    }

    public static function isNotifiedKind(int $kind): bool
    {
        return in_array($kind, self::NOTIFIED_KINDS, true);
    }

    /**
     * Resolve the coordinate (`kind:pubkey:d`) of an addressable event, or null
     * for non-addressable kinds.
     */
    public static function coordinateOf(Event $event): ?string
    {
        $kind = $event->getKind();
        if ($kind < 30000 || $kind > 39999) {
            return null;
        }
        $d = $event->getDTag() ?? '';
        return $kind . ':' . $event->getPubkey() . ':' . $d;
    }

    /**
     * @return array<int, string> list of `a`-tag coordinate values on the event
     */
    private static function aTagValues(Event $event): array
    {
        $out = [];
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'a' && isset($tag[1]) && is_string($tag[1])) {
                $out[] = $tag[1];
            }
        }
        return $out;
    }

    /** @return array<int, string> list of lowercased `t`-tag values on the event */
    private static function tTagValues(Event $event): array
    {
        $out = [];
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 't' && isset($tag[1]) && is_string($tag[1])) {
                $out[] = strtolower($tag[1]);
            }
        }
        return $out;
    }

    /**
     * @return UpdateSubscription[] Unique list of matching subscriptions.
     *                               Indexed by `userId` as the array key to enforce one-per-user.
     */
    public function match(Event $event): array
    {
        if (!self::isNotifiedKind($event->getKind())) {
            return [];
        }

        /** @var array<int, UpdateSubscription> $matched keyed by user id */
        $matched = [];

        // 1. NPUB: any subscription to this event's author.
        $npubHits = $this->subscriptionRepository->findActiveBySourceValues(
            UpdateSourceTypeEnum::NPUB,
            [$event->getPubkey()]
        );
        foreach ($npubHits as $sub) {
            $matched[(int) $sub->getUser()->getId()] ??= $sub;
        }

        // 2. PUBLICATION: the event *is* a 30040 matching a subscribed coordinate,
        //    or the event carries an `a` tag pointing at a subscribed 30040.
        $coordinateCandidates = [];
        $selfCoord = self::coordinateOf($event);
        if ($selfCoord !== null && str_starts_with($selfCoord, KindsEnum::PUBLICATION_INDEX->value . ':')) {
            $coordinateCandidates[] = $selfCoord;
        }
        foreach (self::aTagValues($event) as $a) {
            if (str_starts_with($a, KindsEnum::PUBLICATION_INDEX->value . ':')) {
                $coordinateCandidates[] = $a;
            }
        }
        if ($coordinateCandidates !== []) {
            $pubHits = $this->subscriptionRepository->findActiveBySourceValues(
                UpdateSourceTypeEnum::PUBLICATION,
                array_values(array_unique($coordinateCandidates))
            );
            foreach ($pubHits as $sub) {
                $matched[(int) $sub->getUser()->getId()] ??= $sub;
            }
        }

        // 3. NIP51 sets: expand each subscribed set to (pubkeys, coords, tags)
        //    and check event against each bucket. Only fetch sets that exist in
        //    some user's active subscriptions (cheap initial query).
        $setSubs = $this->subscriptionRepository->findActiveBySourceValues(
            UpdateSourceTypeEnum::NIP51_SET,
            $this->allActiveSetCoordinates()
        );
        if ($setSubs !== []) {
            $eventPubkey = $event->getPubkey();
            $eventCoord = $selfCoord;
            $eventTags = self::tTagValues($event);

            // Group set subs by coordinate so each coord is expanded once.
            /** @var array<string, UpdateSubscription[]> $bySetCoord */
            $bySetCoord = [];
            foreach ($setSubs as $sub) {
                $bySetCoord[$sub->getSourceValue()][] = $sub;
            }

            foreach ($bySetCoord as $setCoord => $subs) {
                $expansion = $this->expandSet($setCoord);
                if ($expansion === null) {
                    continue;
                }
                $matches = in_array($eventPubkey, $expansion['pubkeys'], true)
                    || ($eventCoord !== null && in_array($eventCoord, $expansion['coords'], true))
                    || array_intersect($eventTags, $expansion['tags']) !== [];
                if (!$matches) {
                    continue;
                }
                foreach ($subs as $sub) {
                    $matched[(int) $sub->getUser()->getId()] ??= $sub;
                }
            }
        }

        return array_values($matched);
    }

    /** @return string[] */
    private function allActiveSetCoordinates(): array
    {
        $grouped = $this->subscriptionRepository->findAllActiveGrouped();
        return $grouped['sets'];
    }

    /**
     * Expand a NIP-51 set coordinate (`kind:pubkey:d`) into the authors /
     * coordinates / tags it references.
     *
     * @return array{pubkeys: string[], coords: string[], tags: string[]}|null
     */
    private function expandSet(string $setCoord): ?array
    {
        $parts = explode(':', $setCoord, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$kindStr, $pubkey, $d] = $parts;
        $kind = (int) $kindStr;
        if (!in_array($kind, self::SET_KINDS, true)) {
            return null;
        }

        $setEvent = $this->eventRepository->findByNaddr($kind, $pubkey, $d);
        if ($setEvent === null) {
            return null;
        }

        $pubkeys = [];
        $coords = [];
        $tags = [];
        foreach ($setEvent->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0])) {
                continue;
            }
            switch ($tag[0]) {
                case 'p':
                    if (isset($tag[1]) && is_string($tag[1])) {
                        $pubkeys[] = $tag[1];
                    }
                    break;
                case 'a':
                    if (isset($tag[1]) && is_string($tag[1])) {
                        $coords[] = $tag[1];
                    }
                    break;
                case 't':
                    if (isset($tag[1]) && is_string($tag[1])) {
                        $tags[] = strtolower($tag[1]);
                    }
                    break;
            }
        }

        return [
            'pubkeys' => array_values(array_unique($pubkeys)),
            'coords' => array_values(array_unique($coords)),
            'tags' => array_values(array_unique($tags)),
        ];
    }
}

