<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisCacheService;
use Doctrine\ORM\EntityManagerInterface;
use nostriphant\NIP19\Bech32;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use swentel\nostr\Key\Key;

/**
 * Lists all known spell events (kind 777, NIP-A7) from the database.
 *
 * Spells are regular (non-replaceable) events — no d-tag deduplication.
 * Each spell is addressed by nevent.
 */
#[AsTwigComponent]
final class SpellList
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedisCacheService $redisCacheService,
    ) {}

    /**
     * @return array<int, array{
     *     name: string,
     *     description: ?string,
     *     content: string,
     *     kinds: int[],
     *     topics: string[],
     *     pubkey: string,
     *     npub: string,
     *     nevent: string,
     *     eventId: string,
     *     authorName: string,
     *     authorPicture: string,
     *     createdAt: int,
     * }>
     */
    public function getSpells(): array
    {
        $events = $this->em->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::SPELL->value],
            ['created_at' => 'DESC']
        );

        $parsed = [];
        foreach ($events as $event) {
            $name = '';
            $description = null;
            $kinds = [];
            $topics = [];

            foreach ($event->getTags() as $tag) {
                if (!is_array($tag) || empty($tag)) {
                    continue;
                }
                $k = $tag[0] ?? '';
                if ($k === 'name' || $k === 'title') {
                    $name = $tag[1] ?? $name;
                } elseif ($k === 'alt' && !$description) {
                    $description = $tag[1] ?? null;
                } elseif ($k === 'summary') {
                    $description = $tag[1] ?? $description;
                } elseif ($k === 'k' && isset($tag[1])) {
                    $kinds[] = (int) $tag[1];
                } elseif ($k === 't' && isset($tag[1])) {
                    $topics[] = $tag[1];
                }
            }

            if (!$description && $event->getContent()) {
                $description = $event->getContent();
            }
            if (!$name) {
                $name = substr($event->getId(), 0, 8);
            }

            $parsed[] = [
                'event' => $event,
                'name' => $name,
                'description' => $description,
                'kinds' => array_values(array_unique($kinds)),
                'topics' => array_values(array_unique($topics)),
            ];
        }

        $authorPubkeys = array_unique(array_map(fn($p) => $p['event']->getPubkey(), $parsed));
        $metadataMap = !empty($authorPubkeys)
            ? $this->redisCacheService->getMultipleMetadata($authorPubkeys)
            : [];

        $keyHelper = new Key();
        $result = [];
        foreach ($parsed as $p) {
            $event = $p['event'];
            $pubkey = $event->getPubkey();
            $meta = $metadataMap[$pubkey] ?? null;
            $std = $meta instanceof UserMetadata ? $meta->toStdClass() : $meta;

            try {
                $npub = $keyHelper->convertPublicKeyToBech32($pubkey);
            } catch (\Throwable) {
                $npub = $pubkey;
            }

            try {
                $nevent = (string) Bech32::nevent(
                    id: $event->getId(),
                    relays: [],
                    author: $pubkey,
                    kind: KindsEnum::SPELL->value,
                );
            } catch (\Throwable) {
                $nevent = $event->getId();
            }

            $result[] = [
                'name' => $p['name'],
                'description' => $p['description'],
                'content' => $event->getContent(),
                'kinds' => $p['kinds'],
                'topics' => $p['topics'],
                'pubkey' => $pubkey,
                'npub' => $npub,
                'nevent' => $nevent,
                'eventId' => $event->getId(),
                'authorName' => $std->display_name ?? $std->name ?? '',
                'authorPicture' => $std->picture ?? '',
                'createdAt' => $event->getCreatedAt(),
            ];
        }

        return $result;
    }
}


