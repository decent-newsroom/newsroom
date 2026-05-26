<?php

namespace App\Twig\Components\Organisms;

use App\Service\Graph\GraphMagazineListService;
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
                // Normalize npub to lowercase
                $npub = strtolower(trim($npub));
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
        }
    }
}
