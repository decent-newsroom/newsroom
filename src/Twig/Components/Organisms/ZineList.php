<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\MagazineRepository;
use App\Service\Graph\GraphMagazineListService;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ZineList
{
    public array $nzines = [];
    public ?string $currentUserPubkey = null;

    /** Optional: when set, only magazines owned by this hex pubkey are shown. */
    public ?string $pubkey = null;

    public function __construct(
        private readonly GraphMagazineListService $graphMagazineList,
        /** @deprecated Use GraphMagazineListService instead */
        private readonly MagazineRepository $magazineRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function mount($pubkey = null): void
    {
        $this->pubkey = $pubkey;
        // Resolve current user's hex pubkey for ownership checks
        $token = $this->tokenStorage->getToken();
        $npub = $token?->getUserIdentifier();
        if ($npub) {
            try {
                $key = new Key();
                $this->currentUserPubkey = $key->convertToHex($npub);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Primary: graph-backed listing
        if ($this->pubkey) {
            $graphResults = $this->graphMagazineList->listByPubkey($this->pubkey);
        } else {
            $graphResults = $this->graphMagazineList->listAllMagazines();
        }

        if (!empty($graphResults)) {
            $this->nzines = $graphResults;
            return;
        }

        // Fallback 1: Magazine entities (deprecated — will be removed)
        if ($this->pubkey) {
            $this->nzines = $this->magazineRepository->findByPubkey($this->pubkey);
        } else {
            $this->nzines = $this->magazineRepository->findAllPublished();
        }
        if (!empty($this->nzines)) {
            return;
        }

        // Fallback 2: filter Event entities (legacy)
        $criteria = ['kind' => KindsEnum::PUBLICATION_INDEX];
        if ($this->pubkey) {
            $criteria['pubkey'] = $this->pubkey;
        }
        $allIndices = $this->entityManager->getRepository(Event::class)->findBy($criteria);

        $filtered = array_filter($allIndices, function (Event $index) {
            $tags = $index->getTags();
            $isMagType = false;
            $isTopLevel = false;
            foreach ($tags as $tag) {
                if (($tag[0] ?? '') === 'type' && ($tag[1] ?? '') === 'magazine') {
                    $isMagType = true;
                }
                if (($tag[0] ?? '') === 'a' && !$isTopLevel) {
                    $parts = explode(':', $tag[1] ?? '');
                    if (($parts[0] ?? '') === (string) KindsEnum::PUBLICATION_INDEX->value) {
                        $isTopLevel = true;
                    }
                }
            }
            return $isMagType && $isTopLevel;
        });

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
