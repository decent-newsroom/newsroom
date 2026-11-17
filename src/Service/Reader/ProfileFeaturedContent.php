<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Dto\EventWorkspace\EventPresentation;
use App\Repository\EventRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\NostrKeyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ProfileFeaturedContent
{
    public function __construct(
        private EventRepository $events,
        private EventPreviewAccessPolicy $access,
        private Security $security,
        private NostrKeyService $keys,
    ) {}

    /**
     * Cached relationships select candidates; only current, authorized local tags supply titles.
     *
     * @param array<string, mixed> $editorial
     * @return list<array{type: string, key: string, timestamp: int, item: object|array<array-key, mixed>, labels: list<string>}>
     */
    public function load(array $editorial): array
    {
        $identities = $types = [];
        foreach ([
            'featuredMagazines' => 'magazine',
            'featuredReadingLists' => 'reading_list',
            'featuredInFollowPacks' => 'follow_pack',
        ] as $source => $type) {
            foreach ($editorial[$source] ?? [] as $item) {
                $pubkey = is_array($item) ? ($item['pubkey'] ?? null) : ($item->pubkey ?? null);
                $slug = is_array($item) ? ($item['slug'] ?? $item['dTag'] ?? null) : ($item->slug ?? $item->dTag ?? null);
                if (!is_string($pubkey) || preg_match('/^[0-9a-f]{64}$/iD', $pubkey) !== 1 || !is_string($slug)) {
                    continue;
                }
                $kind = $type === 'follow_pack' ? 39089 : 30040;
                $pubkey = strtolower($pubkey);
                $coordinate = $kind . ':' . $pubkey . ':' . $slug;
                $identities[$coordinate] = ['kind' => $kind, 'pubkey' => $pubkey, 'dTag' => $slug];
                $types[$coordinate] = $type;
            }
        }
        if ($identities === []) {
            return [];
        }

        $viewer = $this->security->getUser();
        $muted = $viewer instanceof UserInterface
            ? $this->access->inventoryMuteSet($this->keys->convertToHex($viewer->getUserIdentifier()))
            : [];
        $sources = [];
        foreach (array_chunk($identities, EventPreviewAccessPolicy::INVENTORY_BATCH_SIZE, preserve_keys: true) as $batch) {
            $targets = $this->events->findReadingNookSubscriptionTargets(array_values($batch));
            $candidates = [];
            foreach ($batch as $coordinate => $identity) {
                $event = $targets[$coordinate] ?? null;
                if ($event === null || $event->getKind() !== $identity['kind']
                    || strtolower($event->getPubkey()) !== $identity['pubkey']
                    || EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())
                    || EventPresentation::hasSensitiveTagEvidence($event->getTags())) {
                    continue;
                }
                $candidates[] = $event;
            }
            foreach ($this->access->readableInventoryEvents($candidates, $muted) as $event) {
                $slug = '';
                $title = null;
                $firstD = true;
                /** @var array<array-key, mixed> $tags */
                $tags = $event->getTags();
                foreach ($tags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    if (($tag[0] ?? null) === 'd' && $firstD) {
                        $slug = $tag[1] ?? null;
                        $firstD = false;
                    } elseif (($tag[0] ?? null) === 'title' && $title === null && is_string($tag[1] ?? null)) {
                        $title = $tag[1];
                    }
                }
                $pubkey = strtolower($event->getPubkey());
                if (!is_string($slug)) {
                    continue;
                }
                $coordinate = $event->getKind() . ':' . $pubkey . ':' . $slug;
                if (!isset($batch[$coordinate])) {
                    continue;
                }
                $sources[] = ['type' => $types[$coordinate], 'items' => [[
                    'pubkey' => $pubkey, 'slug' => $slug, 'title' => $title ?: $slug,
                    'indexEventId' => $event->getId(), 'createdAt' => $event->getCreatedAt(),
                ]]];
            }
        }
        return (new ContentStreamBuilder())->build($sources);
    }
}
