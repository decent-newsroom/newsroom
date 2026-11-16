<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\NostrKeyService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/** Bounded local publication indexes; never resolves their children. */
final readonly class PublicationIndexStream
{
    public const SOURCE_LIMIT = 200;

    public function __construct(
        private EventRepository $events,
        private EventPreviewAccessPolicy $access,
        private Security $security,
        private NostrKeyService $keys,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, seen: array<string, true>}
     * The seen coordinates include denied revisions so a cached older revision
     * cannot replace a current restricted one.
     */
    public function load(?string $author = null): array
    {
        $criteria = ['kind' => KindsEnum::PUBLICATION_INDEX->value];
        if ($author !== null) {
            $criteria['pubkey'] = $author;
        }
        $events = $this->events->findBy($criteria, ['created_at' => 'DESC', 'id' => 'ASC'], self::SOURCE_LIMIT);
        $seen = [];
        $candidates = [];
        foreach ($events as $event) {
            if (!$event instanceof Event || preg_match('/^[0-9a-f]{64}$/iD', $event->getId()) !== 1
                || preg_match('/^[0-9a-f]{64}$/iD', $event->getPubkey()) !== 1) {
                continue;
            }
            $slug = '';
            /** @var array<array-key, mixed> $tags */
            $tags = $event->getTags();
            foreach ($tags as $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                    $slug = $tag[1] ?? null;
                    break;
                }
            }
            if (!is_string($slug)) {
                continue;
            }
            $coordinate = strtolower($event->getPubkey()) . ':30040:' . $slug;
            if (isset($seen[$coordinate])) {
                continue;
            }
            $seen[$coordinate] = true;
            if (EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())
                || EventPresentation::hasSensitiveTagEvidence($event->getTags())) {
                continue;
            }
            $candidates[] = $event;
        }

        $viewer = $this->security->getUser();
        $muted = $viewer instanceof UserInterface
            ? $this->access->inventoryMuteSet($this->keys->convertToHex($viewer->getUserIdentifier()))
            : [];
        $items = [];
        foreach (array_chunk($candidates, EventPreviewAccessPolicy::INVENTORY_BATCH_SIZE) as $batch) {
            foreach ($this->access->readableInventoryEvents($batch, $muted) as $event) {
                $fields = ['d' => '', 'title' => null, 'summary' => null, 'image' => null];
                $firstD = true;
                /** @var array<array-key, mixed> $tags */
                $tags = $event->getTags();
                foreach ($tags as $tag) {
                    if (!is_array($tag) || !is_string($tag[0] ?? null) || !is_string($tag[1] ?? null)) {
                        continue;
                    }
                    if ($tag[0] === 'd' && $firstD) {
                        $fields['d'] = $tag[1];
                        $firstD = false;
                    } elseif (array_key_exists($tag[0], $fields) && $tag[0] !== 'd' && $fields[$tag[0]] === null) {
                        $fields[$tag[0]] = $tag[1];
                    }
                }
                $image = $fields['image'];
                if ($image !== null && (filter_var($image, FILTER_VALIDATE_URL) === false
                    || !in_array(strtolower((string) parse_url($image, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                    $image = null;
                }
                $items[] = [
                    'pubkey' => strtolower($event->getPubkey()), 'slug' => $fields['d'],
                    'indexEventId' => strtolower($event->getId()), 'createdAt' => $event->getCreatedAt(),
                    'title' => $fields['title'] ?: ($fields['d'] !== '' ? $fields['d'] : null),
                    'summary' => $fields['summary'], 'image' => $image,
                ];
            }
        }
        return ['items' => $items, 'seen' => $seen];
    }

    /** @param array<string, true> $seen */
    public static function isSuperseded(mixed $item, array $seen): bool
    {
        if (is_array($item)) {
            $pubkey = $item['pubkey'] ?? null;
            $slug = $item['slug'] ?? $item['dTag'] ?? null;
        } elseif (is_object($item)) {
            $pubkey = method_exists($item, 'getPubkey') ? $item->getPubkey() : ($item->pubkey ?? null);
            $slug = method_exists($item, 'getSlug') ? $item->getSlug() : ($item->slug ?? $item->dTag ?? null);
        } else {
            return false;
        }
        return is_string($pubkey) && is_string($slug) && isset($seen[strtolower($pubkey) . ':30040:' . $slug]);
    }
}
