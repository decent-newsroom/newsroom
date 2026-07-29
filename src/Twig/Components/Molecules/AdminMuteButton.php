<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Service\MutedPubkeysService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Admin-only shortcut to mute an author (ROLE_MUTED) directly from their
 * profile, without visiting the administration dashboard.
 *
 * Admin gating is handled in the template via is_granted('ROLE_ADMIN').
 * The button is only rendered while the target author is not already muted.
 */
#[AsLiveComponent('Molecules:AdminMuteButton')]
final class AdminMuteButton
{
    use DefaultActionTrait;

    /** npub of the author to mute */
    #[LiveProp]
    public string $npub = '';

    /** Internal state — resolved on first render */
    #[LiveProp(writable: true)]
    public bool $muted = false;

    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly MutedPubkeysService $mutedPubkeysService,
    ) {}

    public function mount(): void
    {
        $user = $this->userRepository->findOneBy(['npub' => $this->npub]);
        $this->muted = $user !== null
            && in_array(RolesEnum::MUTED->value, $user->getRoles(), true);
    }

    #[LiveAction]
    public function mute(): void
    {
        if (!str_starts_with($this->npub, 'npub1')) {
            return;
        }

        $user = $this->userRepository->findOneBy(['npub' => $this->npub]);

        if ($user === null) {
            $user = new User();
            $user->setNpub($this->npub);
            $user->setRoles([RolesEnum::MUTED->value]);
            $this->em->persist($user);
        } else {
            $user->addRole(RolesEnum::MUTED->value);
        }

        $this->em->flush();
        $this->mutedPubkeysService->refreshCache();
        $this->muted = true;
    }
}
