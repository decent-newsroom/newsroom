<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders a feature-highlight promo banner for Essayist on the authenticated home feed.
 *
 * Hidden automatically when the current user is already an Essayist member.
 * Shows early-bird copy before launch, regular join copy after.
 *
 * Usage: <twig:Organisms:EssayistPromo />
 */
#[AsTwigComponent]
final class EssayistPromo
{
    private const LAUNCH_DATE = '2026-06-01T00:00:00+00:00';

    public bool $isVisible   = false;
    public bool $isLaunched  = false;
    public bool $isEarlyBird = false;
    public bool $isLoggedIn  = false;

    public function __construct(private readonly Security $security)
    {
    }

    public function mount(): void
    {
        $user = $this->security->getUser();

        if ($user instanceof User) {
            $this->isLoggedIn = true;

            // Already a member — hide the promo entirely
            if (in_array('ROLE_ESSAYIST_MEMBER', $user->getRoles(), true)) {
                return;
            }

            $this->isEarlyBird = in_array('ROLE_ESSAYIST_EARLY_BIRD', $user->getRoles(), true);
        }

        $this->isVisible  = true;
        $launchDate       = new \DateTimeImmutable(self::LAUNCH_DATE);
        $now              = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->isLaunched = $now >= $launchDate;
    }
}

