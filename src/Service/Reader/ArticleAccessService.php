<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Article;
use App\Enum\RolesEnum;
use App\Service\Nostr\NostrIdentityService;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class ArticleAccessService
{
    public function __construct(
        private NostrIdentityService $identityService,
    ) {
    }

    public function canViewEssayistExclusive(?UserInterface $viewer, ?Article $article = null): bool
    {
        if (!$viewer instanceof UserInterface) {
            return false;
        }

        $roles = $viewer->getRoles();
        if (
            in_array('ROLE_ADMIN', $roles, true)
            || in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)
            || in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $roles, true)
        ) {
            return true;
        }

        if (!$article instanceof Article) {
            return false;
        }

        $viewerPubkey = $this->viewerPubkey($viewer);
        if ($viewerPubkey === null) {
            return false;
        }

        return hash_equals(strtolower((string) $article->getPubkey()), $viewerPubkey);
    }

    public function canEdit(?UserInterface $viewer, Article $article): bool
    {
        $viewerPubkey = $viewer instanceof UserInterface ? $this->viewerPubkey($viewer) : null;
        if ($viewerPubkey === null) {
            return false;
        }

        return hash_equals(strtolower((string) $article->getPubkey()), $viewerPubkey);
    }

    private function viewerPubkey(UserInterface $viewer): ?string
    {
        try {
            return strtolower($this->identityService->toHex($viewer->getUserIdentifier()));
        } catch (\Throwable) {
            return null;
        }
    }
}