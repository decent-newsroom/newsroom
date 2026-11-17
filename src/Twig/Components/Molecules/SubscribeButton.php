<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Update\SubscriptionSource;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The same local subscription action for profiles and collections.
 */
#[AsLiveComponent('Molecules:SubscribeButton')]
final class SubscribeButton
{
    use DefaultActionTrait;

    /** Hex pubkey of the author to subscribe to */
    #[LiveProp]
    public string $pubkey = '';

    #[LiveProp]
    public string $identifier = '';

    #[LiveProp]
    public bool $compact = false;

    #[LiveProp]
    public bool $subscribed = false;

    #[LiveProp]
    public string $error = '';

    public function __construct(
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly SubscriptionSource $sources,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    // Symfony calls mount() before assigning props that are not mount arguments.
    public function mount(string $pubkey = '', string $identifier = ''): void
    {
        $this->pubkey = $pubkey !== '' ? $pubkey : $this->pubkey;
        $this->identifier = $identifier !== '' ? $identifier : $this->identifier;

        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        try {
            [$type, $value] = $this->sources->parse($this->identifier ?: $this->pubkey);
        } catch (\InvalidArgumentException) {
            $this->error = 'updates.errors.invalidInput';
            return;
        }
        $existing = $this->subscriptionRepository->findOneForUser(
            $user,
            $type,
            $value,
        );

        $this->subscribed = $existing?->isActive() ?? false;
    }

    #[LiveAction]
    public function toggle(): void
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        try {
            [$type, $value, $label] = $this->sources->parse($this->identifier ?: $this->pubkey);
        } catch (\InvalidArgumentException) {
            $this->error = 'updates.errors.invalidInput';
            return;
        }
        $existing = $this->subscriptionRepository->findOneForUser(
            $user,
            $type,
            $value,
        );

        if ($existing?->isActive()) {
            $existing->setActive(false);
            $this->em->flush();
            $this->subscribed = false;
            $this->error = '';
            return;
        }

        $subscription = $existing ?? new UpdateSubscription($user, $type, $value, $label);
        $subscription->reactivate();
        $this->em->persist($subscription);
        $this->em->flush();
        $this->subscribed = true;
        $this->error = '';
    }
}
