<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\UpdateProService;

/**
 * Single source of truth for what a user is allowed to do with updates,
 * based on whether they have an active Updates Pro subscription.
 *
 * Free tier:
 *   - Up to {@see UpdateProService::FREE_SUBSCRIPTION_CAP} active subscriptions
 *     of type NPUB or PUBLICATION.
 *   - NIP-51 SET subscriptions are not permitted.
 *
 * Updates Pro:
 *   - Unlimited subscriptions of all source types (NPUB, PUBLICATION, NIP51_SET).
 */
class UpdateAccessService
{
    public function __construct(
        private readonly UpdateSubscriptionRepository $subscriptionRepository,
        private readonly UpdateProService $proService,
    ) {
    }

    /**
     * Whether the user may add one more subscription of the given type.
     */
    public function canAddSubscription(User $user, UpdateSourceTypeEnum $type): bool
    {
        if ($this->isPro($user)) {
            return true;
        }

        // Free users cannot subscribe to NIP-51 sets at all.
        if ($type === UpdateSourceTypeEnum::NIP51_SET) {
            return false;
        }

        $currentCount = $this->subscriptionRepository->countActiveForUser($user);
        return $currentCount < UpdateProService::FREE_SUBSCRIPTION_CAP;
    }

    /**
     * Return an error key (translation key suffix) if the user cannot add this
     * subscription type, or null when allowed.
     */
    public function blockReason(User $user, UpdateSourceTypeEnum $type): ?string
    {
        if ($this->isPro($user)) {
            return null;
        }
        if ($type === UpdateSourceTypeEnum::NIP51_SET) {
            return 'updatesPro.required.nip51Sets';
        }
        $currentCount = $this->subscriptionRepository->countActiveForUser($user);
        if ($currentCount >= UpdateProService::FREE_SUBSCRIPTION_CAP) {
            return 'updatesPro.required.cap';
        }
        return null;
    }

    public function isPro(User $user): bool
    {
        // Admin is always considered Pro (convenience for testing).
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        return $user->isUpdatesProSubscriber();
    }

    public function getFreeCap(): int
    {
        return UpdateProService::FREE_SUBSCRIPTION_CAP;
    }
}

