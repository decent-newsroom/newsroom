<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Article;
use App\Entity\Event;
use App\Entity\Update;
use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\SubscriptionChangeRepository;
use App\Repository\UpdateRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\NostrNip19Service;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

/** @phpstan-import-type Member from SubscriptionDiffer */
final readonly class SubscriptionFeed
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private UpdateRepository $updates,
        private EventRepository $events,
        private ArticleRepository $articles,
        private SubscriptionChangeRepository $changes,
        private SubscriptionSource $sources,
        private EventPreviewAccessPolicy $access,
        private UpdateMatcher $matcher,
        private NostrNip19Service $nip19,
    ) {}

    /** @return array{items: list<Update>, nextCursor: ?string} */
    public function page(User $user, ?string $before = null): array
    {
        $rows = $this->updates->findRecentForUser($user, self::PAGE_SIZE, $before);
        $visible = [];
        foreach ($rows as $update) {
            if ($this->isReadable($update)) {
                $this->decorate($update);
                $visible[] = $update;
            }
        }
        return [
            'items' => $visible,
            'nextCursor' => count($rows) === self::PAGE_SIZE ? $rows[array_key_last($rows)]->getId() : null,
        ];
    }

    /** @return array{unread: int, unseen: int} */
    public function unreadCounts(User $user): array
    {
        $unread = $unseen = 0;
        foreach ($this->updates->unreadForUser($user) as $update) {
            if ($this->isReadable($update)) {
                ++$unread;
                $unseen += $update->getSeenAt() === null ? 1 : 0;
            }
        }
        return ['unread' => $unread, 'unseen' => $unseen];
    }

    public function isReadable(Update $update): bool
    {
        $historical = $update->getChange()?->metadataEvent();
        $event = $this->events->find($update->getEventId());
        if (!$event instanceof Event && $historical !== null) {
            if ($historical->getKind() === 30023) {
                $event = $historical;
            } else {
                $type = $historical->getKind() === 30040 ? UpdateSourceTypeEnum::PUBLICATION : UpdateSourceTypeEnum::NIP51_SET;
                $event = $this->sources->currentEvent($type, $update->getChange()->getCoordinate());
            }
        }
        if (!$event instanceof Event || !$this->isEventReadable($event)
            || ($historical !== null && !$this->isMetadataReadable($historical))) {
            return false;
        }
        $subscription = $update->getSubscription();
        if ($subscription !== null && $subscription->getSourceType() !== UpdateSourceTypeEnum::NPUB) {
            $source = $this->sources->currentEvent($subscription->getSourceType(), $subscription->getSourceValue());
            if ($source === null || !$this->isEventReadable($source)) {
                return false;
            }
            if ($source->getKind() === 30040 && UpdateMatcher::coordinateOf($source) !== $update->getEventCoordinate()
                && !$this->matcher->publicationContains($source, $historical ?? $event, $this->isEventReadable(...))) {
                return false;
            }
        }
        return true;
    }

    public function isEventReadable(Event $event): bool
    {
        if (!$this->isMetadataReadable($event)) {
            return false;
        }
        if ($event->getKind() !== 30023) {
            return true;
        }
        $article = $this->articles->findOneBy(['eventId' => $event->getId()]);
        return $article !== null && $this->access->isArticleReadable(
            $article, $event->getPubkey(), $event->getDTag() ?? '',
        );
    }

    private function isMetadataReadable(Event $event): bool
    {
        return !EventPresentation::hasSensitiveTagEvidence($event->getTags())
            && !EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())
            && $this->access->isReadable($event);
    }

    private function decorate(Update $update): void
    {
        $subscription = $update->getSubscription();
        if ($subscription !== null && $subscription->getSourceType() !== UpdateSourceTypeEnum::NPUB) {
            $source = $this->sources->currentEvent($subscription->getSourceType(), $subscription->getSourceValue());
            if ($source !== null && $this->isEventReadable($source)) {
                $update->setVisibleSource([
                    'label' => $source->getTitle() ?: substr($source->getId(), 0, 16) . '...',
                    'url' => '/e/' . $this->nip19->encodeNote($source->getId()),
                ]);
            }
        }
        $change = $update->getChange();
        if ($change === null) {
            return;
        }
        $delta = $change->getChanges();
        if ($change->getPreviousEventId() !== null) {
            $previous = $this->changes->find($change->getPreviousEventId());
            if ($previous === null || !$this->isMetadataReadable($previous->metadataEvent())) {
                $update->setVisibleChanges([]);
                return;
            }
        }
        $details = [
            'added' => [], 'removed' => [],
            'reordered' => $delta['reordered'] ?? false,
            'metadata' => ($delta['metadata'] ?? []) !== [],
            'content' => $delta['content'] ?? false,
            'truncated' => false,
            'unavailable' => false,
        ];
        $remaining = 50;
        foreach (['added', 'removed'] as $direction) {
            foreach ($delta[$direction] ?? [] as $member) {
                if ($remaining-- <= 0) {
                    $details['truncated'] = true;
                    break;
                }
                $resolved = $this->member($member);
                if ($resolved !== null) {
                    $details[$direction][] = $resolved;
                } else {
                    $details['unavailable'] = true;
                }
            }
        }
        $update->setVisibleChanges($details);
    }

    /**
     * @param Member $member
     * @return array{label: string, url: ?string}|null
     */
    private function member(array $member): ?array
    {
        $target = $member['target'];
        if ($member['type'] === 'topic') {
            return ['label' => '#' . $target, 'url' => null];
        }
        if ($member['type'] === 'profile') {
            $event = $this->events->findLatestMetadataByPubkey($target);
            if ($event === null || !$this->isMetadataReadable($event)) {
                return null;
            }
            $npub = PublicKey::fromHex($target)?->toBech32();
            return $npub === null ? null : ['label' => substr($npub, 0, 16) . '...', 'url' => '/p/' . $npub];
        }
        $event = null;
        if ($member['type'] === 'event_id' || ($member['pin'] ?? null) !== null) {
            $event = $this->events->find($member['pin'] ?? $target);
            if ($event instanceof Event && $member['type'] === 'address' && UpdateMatcher::coordinateOf($event) !== $target) {
                return null;
            }
        } elseif ($member['type'] === 'address') {
            [$kind, $author, $identifier] = explode(':', $target, 3);
            $event = $this->events->findByNaddr((int) $kind, $author, $identifier);
        }
        if (!$event instanceof Event) {
            $article = null;
            if ($member['type'] === 'event_id' || $member['pin'] !== null) {
                $article = $this->articles->findOneBy(['eventId' => $member['pin'] ?? $target]);
                if ($article !== null && $member['type'] === 'address'
                    && '30023:' . $article->getPubkey() . ':' . $article->getSlug() !== $target) {
                    return null;
                }
            } elseif ($member['type'] === 'address' && str_starts_with($target, '30023:')) {
                [, $author, $identifier] = explode(':', $target, 3);
                $article = $this->articles->findOneBy(
                    ['kind' => KindsEnum::LONGFORM, 'pubkey' => $author, 'slug' => $identifier],
                    ['createdAt' => 'DESC', 'eventId' => 'ASC'],
                );
            }
            return $this->articleMember($article, $member['type'] === 'event_id' || $member['pin'] !== null);
        }
        if (!$this->isEventReadable($event)) {
            return null;
        }
        return [
            'label' => $event->getTitle() ?: substr($event->getId(), 0, 16) . '...',
            'url' => '/e/' . $this->nip19->encodeNote($event->getId()),
        ];
    }

    /** @return array{label: string, url: string}|null */
    private function articleMember(?Article $article, bool $exact): ?array
    {
        if ($article === null || !$this->access->isArticleReadable(
            $article, $article->getPubkey() ?? '', $article->getSlug() ?? '',
        ) || EventPresentation::hasSensitiveTagEvidence($article->getRaw()['tags'] ?? [])) {
            return null;
        }
        return [
            'label' => $article->getTitle() ?: substr($article->getEventId() ?? '', 0, 16) . '...',
            'url' => '/e/' . ($exact
                ? $this->nip19->encodeNote($article->getEventId() ?? '')
                : $this->nip19->encodeAddr($article->getPubkey() ?? '', $article->getSlug() ?? '', 30023)),
        ];
    }
}
