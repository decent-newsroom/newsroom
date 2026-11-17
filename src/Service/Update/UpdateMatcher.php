<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\EventRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Dto\EventWorkspace\EventPresentation;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;

class UpdateMatcher
{
    public const NOTIFIED_KINDS = [30023, ...SubscriptionSource::COLLECTION_KINDS];
    private const MAX_INDEX_DEPTH = 6;
    private const MAX_INDEX_NODES = 128;

    public function __construct(
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly EventRepository $eventRepository,
        private readonly SubscriptionSource $sources,
        private readonly SubscriptionDiffer $differ,
    ) {}

    public static function isNotifiedKind(int $kind): bool
    {
        return in_array($kind, self::NOTIFIED_KINDS, true);
    }

    public static function coordinateOf(Event $event): ?string
    {
        if (in_array($event->getKind(), [0, 3], true)
            || ($event->getKind() >= 10000 && $event->getKind() < 20000)) {
            return $event->getKind() . ':' . $event->getPubkey() . ':';
        }
        if ($event->getKind() < 30000 || $event->getKind() > 39999) {
            return null;
        }
        return $event->getKind() . ':' . $event->getPubkey() . ':' . ($event->getDTag() ?? '');
    }

    /** @return UpdateSubscription[] One delivery per reader, irrespective of matching sources. */
    public function match(Event $event): array
    {
        if (!self::isNotifiedKind($event->getKind())) {
            return [];
        }
        $matched = [];
        foreach ($this->subscriptionRepository->findActiveBySourceValues(
            UpdateSourceTypeEnum::NPUB, [$event->getPubkey()],
        ) as $subscription) {
            // A profile subscription follows published articles and publications,
            // not the author's mute list or other account configuration.
            if ($subscription->getCreatedAt()->getTimestamp() <= $event->getCreatedAt()
                && in_array($event->getKind(), [30023, 30040], true)) {
                $matched[(int) $subscription->getUser()->getId()] = $subscription;
            }
        }

        $grouped = $this->subscriptionRepository->findAllActiveGrouped();
        foreach ([UpdateSourceTypeEnum::PUBLICATION, UpdateSourceTypeEnum::NIP51_SET] as $type) {
            $values = $type === UpdateSourceTypeEnum::PUBLICATION ? $grouped['publications'] : $grouped['sets'];
            foreach ($values as $value) {
                $source = $this->sources->currentEvent($type, $value);
                if ($source === null) {
                    continue;
                }
                $self = $event->getKind() . ':' . $event->getPubkey() . ':'
                    . ($event->getKind() >= 30000 ? ($event->getDTag() ?? '') : '');
                $matches = $self === $value;
                if (!$matches && $source->getKind() === 30040) {
                    $matches = $this->publicationContains($source, $event, static fn (Event $index): bool =>
                        !EventPresentation::hasSensitiveTagEvidence($index->getTags())
                        && !EventWorkspaceScopePolicy::isScopedInventoryList($index->getKind(), $index->getTags()));
                } elseif (!$matches && $event->getKind() === 30023) {
                    foreach ($this->differ->members($source->getKind(), $source->getTags()) as $member) {
                        if (($member['type'] === 'profile' && $member['target'] === $event->getPubkey())
                            || ($member['type'] === 'address' && $member['target'] === self::coordinateOf($event))
                            || ($member['type'] === 'event_id' && $member['target'] === $event->getId())) {
                            $matches = true;
                            break;
                        }
                        if ($member['type'] === 'topic') {
                            /** @var array<array-key, mixed> $tags */
                            $tags = $event->getTags();
                            foreach ($tags as $tag) {
                                if (is_array($tag) && ($tag[0] ?? null) === 't'
                                    && is_string($tag[1] ?? null) && strtolower($tag[1]) === $member['target']) {
                                    $matches = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                if ($matches) {
                    foreach ($this->subscriptionRepository->findActiveBySourceValues($type, [$value]) as $subscription) {
                        if ($subscription->getCreatedAt()->getTimestamp() <= $event->getCreatedAt()) {
                            $matched[(int) $subscription->getUser()->getId()] ??= $subscription;
                        }
                    }
                }
            }
        }
        return array_values($matched);
    }

    public function publicationContains(Event $index, Event $target, callable $readable): bool
    {
        $remaining = self::MAX_INDEX_NODES;
        return $this->containsReadable($index, $target, [], $remaining, $readable);
    }

    /** @param list<string> $path */
    private function containsReadable(Event $index, Event $target, array $path, int &$remaining, callable $readable): bool
    {
        if ($remaining-- <= 0 || count($path) >= self::MAX_INDEX_DEPTH
            || in_array($index->getId(), $path, true) || !$readable($index)) {
            return false;
        }
        $path[] = $index->getId();
        foreach ($this->differ->members(30040, $index->getTags()) as $member) {
            if (($member['type'] === 'event_id' && $member['target'] === $target->getId())
                || ($member['type'] === 'address' && $member['target'] === self::coordinateOf($target)
                    && ($member['pin'] === null || $member['pin'] === $target->getId()))) {
                return true;
            }
            if ($remaining <= 0) {
                break;
            }
            if ($member['type'] === 'address' && str_starts_with((string) $member['target'], '30040:')) {
                $child = $member['pin'] !== null
                    ? $this->eventRepository->find($member['pin'])
                    : $this->sources->currentEvent(UpdateSourceTypeEnum::PUBLICATION, $member['target']);
                if ($child instanceof Event && self::coordinateOf($child) === $member['target']
                    && $this->containsReadable($child, $target, $path, $remaining, $readable)) {
                    return true;
                }
            }
        }
        return false;
    }
}
