<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ZineList
{
    public array $nzines = [];
    public ?string $currentUserPubkey = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function mount(): void
    {
        // Resolve current user's hex pubkey for ownership checks
        $token = $this->tokenStorage->getToken();
        $npub = $token?->getUserIdentifier();
        if ($npub) {
            try {
                $key = new \swentel\nostr\Key\Key();
                $this->currentUserPubkey = $key->convertToHex($npub);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $nzines = $this->entityManager->getRepository(Event::class)->findBy(['kind' => KindsEnum::PUBLICATION_INDEX]);

        // filter, only keep type === magazine
        $filtered = array_filter($nzines, function ($index) {
            // look for tags
            $tags = $index->getTags();
            $isMagType = false;
            $isTopLevel = false;
            foreach ($tags as $tag) {
                // only if tag 'type' with value 'magazine'
                if ($tag[0] === 'type' && $tag[1] === 'magazine') {
                    $isMagType = true;
                }
                // and only contains other indices:
                // a tags with kind 30040
                if ($tag[0] === 'a' && $isTopLevel === false) {
                    // tag format: ['a', 'kind:pubkey:slug']
                    $parts = explode(':', $tag[1]);
                    if ($parts[0] == (string)KindsEnum::PUBLICATION_INDEX->value) {
                        $isTopLevel = true;
                    }
                }
            }
            return $isMagType && $isTopLevel;
        });

        // Deduplicate by slug
        $uniqueNzines = [];
        foreach ($filtered as $nzine) {
            $slug = $nzine->getSlug();
            if ($slug !== null) {
                $uniqueNzines[$slug] = $nzine;
            }
        }

        $this->nzines = array_values($uniqueNzines);
    }
}
