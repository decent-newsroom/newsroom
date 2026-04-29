<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use swentel\nostr\Key\Key;

#[AsTwigComponent]
final class FollowPackDropdown
{
    /** Hex pubkey of the profile being viewed */
    public string $pubkey = '';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Get the logged-in user's follow packs with their member lists.
     *
     * @return array<int, array{id: string, dTag: string, title: string, memberCount: int, members: string[], eventJson: string}>
     */
    public function getPacksWithMembers(): array
    {
        $user = $this->security->getUser();
        if (!$user) {
            return [];
        }

        $npub = $user->getUserIdentifier();
        $keys = new Key();
        $ownerPubkey = $keys->convertToHex($npub);

        $packs = $this->entityManager->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::FOLLOW_PACK->value, 'pubkey' => $ownerPubkey],
            ['created_at' => 'DESC']
        );

        $result = [];
        $seen = [];
        foreach ($packs as $pack) {
            $dTag = $pack->getSlug() ?? '';
            // Deduplicate by dTag (kind and npub are fixed in this context)
            if ($dTag === '' || isset($seen[$dTag])) {
                continue;
            }
            $seen[$dTag] = true;
            $title = '';
            $members = [];

            foreach ($pack->getTags() as $tag) {
                if (($tag[0] ?? '') === 'title' && isset($tag[1])) {
                    $title = $tag[1];
                }
                if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                    $members[] = $tag[1];
                }
            }

            $result[] = [
                'id' => $pack->getId(),
                'dTag' => $dTag,
                'title' => $title ?: 'Untitled',
                'memberCount' => count($members),
                'members' => $members,
                'eventJson' => json_encode($pack->getTags()),
            ];
        }

        return $result;
    }
}

