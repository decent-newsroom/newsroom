<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UpdateProSubscription;
use App\Enum\UpdateProStatus;
use App\Enum\RolesEnum;
use App\Repository\UpdateProSubscriptionRepository;
use App\Repository\UserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Legacy payment settlement and history for the retired Updates Pro product.
 * No entitlement is required for local content subscriptions.
 */
class UpdateProService
{
    public function __construct(
        private readonly UpdateProSubscriptionRepository $subscriptionRepository,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSubscription(string $npub): ?UpdateProSubscription
    {
        return $this->subscriptionRepository->findByNpub($npub);
    }

    public function hasActiveSubscription(string $npub): bool
    {
        $sub = $this->subscriptionRepository->findByNpub($npub);
        return $sub !== null && $sub->isActive();
    }

    public function activateSubscription(UpdateProSubscription $subscription, string $zapReceiptEventId): void
    {
        $subscription->setZapReceiptEventId($zapReceiptEventId);
        $subscription->activate();
        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Activated Updates Pro subscription', [
            'npub' => $subscription->getNpub(),
            'tier' => $subscription->getTier()->value,
            'expires_at' => $subscription->getExpiresAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    public function renewSubscription(UpdateProSubscription $subscription, string $zapReceiptEventId): void
    {
        $subscription->setZapReceiptEventId($zapReceiptEventId);
        $subscription->renew();
        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Renewed Updates Pro subscription', [
            'npub' => $subscription->getNpub(),
            'tier' => $subscription->getTier()->value,
            'expires_at' => $subscription->getExpiresAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    /** @return UpdateProSubscription[] */
    public function getPendingSubscriptions(): array
    {
        return $this->subscriptionRepository->findWithPendingInvoices();
    }

    public function processExpiredToGrace(): int
    {
        $subs = $this->subscriptionRepository->findExpiredNeedingGraceTransition();
        foreach ($subs as $sub) {
            $sub->setStatus(UpdateProStatus::GRACE);
            $this->subscriptionRepository->save($sub, false);
        }
        if ($subs) {
            $this->entityManager->flush();
        }
        return count($subs);
    }

    public function processGraceEnded(): int
    {
        $subs = $this->subscriptionRepository->findGracePeriodEnded();
        foreach ($subs as $sub) {
            $sub->setStatus(UpdateProStatus::EXPIRED);
            $this->subscriptionRepository->save($sub, false);
            $this->revokeProRole($sub->getNpub());
        }
        if ($subs) {
            $this->entityManager->flush();
        }
        return count($subs);
    }

    private function revokeProRole(string $npub): void
    {
        $user = $this->userRepository->findOneBy(['npub' => $npub]);
        if ($user && $user->isUpdatesProSubscriber()) {
            $user->removeRole(RolesEnum::UPDATES_PRO->value);
            $this->entityManager->flush();
        }
    }
}
