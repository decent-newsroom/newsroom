<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\User;
use App\Enum\NotificationSourceTypeEnum;
use App\Repository\NotificationSubscriptionRepository;
use App\Service\NotificationProService;

/**
 * Single source of truth for what a user is allowed to do with notifications,
 * based on whether they have an active Notifications Pro subscription.
 *
 * Free tier:
 *   - Up to {@see NotificationProService::FREE_SUBSCRIPTION_CAP} active subscriptions
 *     of type NPUB or PUBLICATION.
 *   - NIP-51 SET subscriptions are not permitted.
 *
 * Notifications Pro:
 *   - Unlimited subscriptions of all source types (NPUB, PUBLICATION, NIP51_SET).
 */
class NotificationAccessService
{
    public function __construct(
        private readonly NotificationSubscriptionRepository $subscriptionRepository,
        private readonly NotificationProService $proService,
    ) {
    }

    /**
     * Whether the user may add one more subscription of the given type.
     */
    public function canAddSubscription(User $user, NotificationSourceTypeEnum $type): bool
    {
        if ($this->isPro($user)) {
            return true;
        }

        // Free users cannot subscribe to NIP-51 sets at all.
        if ($type === NotificationSourceTypeEnum::NIP51_SET) {
            return false;
        }

        $currentCount = $this->subscriptionRepository->countActiveForUser($user);
        return $currentCount < NotificationProService::FREE_SUBSCRIPTION_CAP;
    }

    /**
     * Return an error key (translation key suffix) if the user cannot add this
     * subscription type, or null when allowed.
     */
    public function blockReason(User $user, NotificationSourceTypeEnum $type): ?string
    {
        if ($this->isPro($user)) {
            return null;
        }
        if ($type === NotificationSourceTypeEnum::NIP51_SET) {
            return 'notificationsPro.required.nip51Sets';
        }
        $currentCount = $this->subscriptionRepository->countActiveForUser($user);
        if ($currentCount >= NotificationProService::FREE_SUBSCRIPTION_CAP) {
            return 'notificationsPro.required.cap';
        }
        return null;
    }

    public function isPro(User $user): bool
    {
        // Admin is always considered Pro (convenience for testing).
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }
        return $user->isNotificationsProSubscriber();
    }

    public function getFreeCap(): int
    {
        return NotificationProService::FREE_SUBSCRIPTION_CAP;
    }
}

