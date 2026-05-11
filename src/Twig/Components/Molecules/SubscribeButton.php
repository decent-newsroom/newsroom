<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Update\UpdateAccessService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Live subscribe/unsubscribe button for an author pubkey.
 *
 * Renders a single button that toggles the current user's update
 * subscription to the given author. Handles the access cap check
 * inline and shows an error message instead of subscribing when
 * the free tier limit is reached.
 */
#[AsLiveComponent('Molecules:SubscribeButton')]
final class SubscribeButton
{
    use DefaultActionTrait;

    /** Hex pubkey of the author to subscribe to */
    #[LiveProp]
    public string $pubkey = '';

    /** Internal state — resolved on first render */
    #[LiveProp(writable: true)]
    public bool $subscribed = false;

    #[LiveProp(writable: true)]
    public string $error = '';

    public function __construct(
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly UpdateAccessService $accessService,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    public function mount(): void
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if ($user === null) {
            return;
        }

        $existing = $this->subscriptionRepository->findOneForUser(
            $user,
            UpdateSourceTypeEnum::NPUB,
            $this->pubkey,
        );

        $this->subscribed = $existing !== null;
    }

    #[LiveAction]
    public function toggle(): void
    {
        /** @var User|null $user */
        $user = $this->security->getUser();
        if ($user === null) {
            return;
        }

        $existing = $this->subscriptionRepository->findOneForUser(
            $user,
            UpdateSourceTypeEnum::NPUB,
            $this->pubkey,
        );

        if ($existing !== null) {
            // Unsubscribe
            $this->em->remove($existing);
            $this->em->flush();
            $this->subscribed = false;
            $this->error = '';
            return;
        }

        // Subscribe — check access cap
        $blockReason = $this->accessService->blockReason($user, UpdateSourceTypeEnum::NPUB);
        if ($blockReason !== null) {
            $this->error = $blockReason;
            return;
        }

        try {
            $npub = NostrKeyUtil::hexToNpub($this->pubkey);
        } catch (\InvalidArgumentException) {
            $npub = null;
        }
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, $this->pubkey, $npub);
        $this->em->persist($subscription);
        $this->em->flush();
        $this->subscribed = true;
        $this->error = '';
    }
}


