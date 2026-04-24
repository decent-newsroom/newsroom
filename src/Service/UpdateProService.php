<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UpdateProSubscription;
use App\Entity\User;
use App\Enum\ActiveIndexingStatus;
use App\Enum\UpdateProTier;
use App\Enum\RolesEnum;
use App\Repository\UpdateProSubscriptionRepository;
use App\Repository\UserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;

/**
 * Manages paid Updates Pro subscriptions.
 *
 * Mirrors the lifecycle of {@see ActiveIndexingService} (invoice → activate /
 * renew → grace → expire) but without relay-fetch configuration.
 *
 * Free tier limits (enforced by callers / {@see UpdateAccessService}):
 *   - Up to {@see self::FREE_SUBSCRIPTION_CAP} npub + publication subscriptions.
 *   - No NIP-51 set subscriptions.
 * Pro tier:
 *   - Unlimited subscriptions of all source types.
 */
class UpdateProService
{
    /** Maximum update subscriptions for free (non-Pro) users. */
    public const FREE_SUBSCRIPTION_CAP = 5;

    private readonly string $recipientPubkeyHex;

    public function __construct(
        private readonly UpdateProSubscriptionRepository $subscriptionRepository,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LNURLResolver $lnurlResolver,
        private readonly LoggerInterface $logger,
        string $recipientPubkey,
        private readonly string $recipientLud16,
    ) {
        if (str_starts_with($recipientPubkey, 'npub1')) {
            $key = new Key();
            $this->recipientPubkeyHex = $key->convertToHex($recipientPubkey);
        } else {
            $this->recipientPubkeyHex = $recipientPubkey;
        }
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

    /**
     * Create or reuse a pending subscription and generate a BOLT11 invoice.
     *
     * @return array{bolt11: string, amount: int, subscription: UpdateProSubscription}
     */
    public function createSubscriptionInvoice(string $npub, UpdateProTier $tier): array
    {
        $subscription = $this->subscriptionRepository->findByNpub($npub);

        if (!$subscription) {
            $subscription = new UpdateProSubscription($npub, $tier);
        } elseif ($subscription->isExpired() || $subscription->getStatus() === ActiveIndexingStatus::PENDING) {
            $subscription->setTier($tier);
        }

        $amountSats = $tier->getPriceInSats();

        $lnurlInfo = $this->lnurlResolver->resolve($this->recipientLud16);
        $bolt11 = $this->lnurlResolver->requestInvoice(
            callback: $lnurlInfo->callback,
            amountMillisats: $amountSats * 1000,
            nostrEvent: null,
            lnurl: $lnurlInfo->bech32
        );

        $subscription->setPendingInvoiceBolt11($bolt11);
        $subscription->setStatus(ActiveIndexingStatus::PENDING);
        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Created Updates Pro invoice', [
            'npub' => $npub,
            'tier' => $tier->value,
            'amount_sats' => $amountSats,
        ]);

        return [
            'bolt11' => $bolt11,
            'amount' => $amountSats,
            'subscription' => $subscription,
        ];
    }

    public function activateSubscription(UpdateProSubscription $subscription, string $zapReceiptEventId): void
    {
        $subscription->setZapReceiptEventId($zapReceiptEventId);
        $subscription->activate();
        $this->subscriptionRepository->save($subscription);

        $this->grantProRole($subscription->getNpub());

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

        $this->grantProRole($subscription->getNpub());

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
            $sub->setStatus(ActiveIndexingStatus::GRACE);
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
            $sub->setStatus(ActiveIndexingStatus::EXPIRED);
            $this->subscriptionRepository->save($sub, false);
            $this->revokeProRole($sub->getNpub());
        }
        if ($subs) {
            $this->entityManager->flush();
        }
        return count($subs);
    }

    public function cancelPendingSubscription(string $npub): void
    {
        $sub = $this->subscriptionRepository->findByNpub($npub);
        if (!$sub) {
            throw new \RuntimeException('No subscription found for this user');
        }
        if ($sub->getStatus() !== ActiveIndexingStatus::PENDING) {
            throw new \RuntimeException('Only pending subscriptions can be cancelled');
        }
        $this->subscriptionRepository->remove($sub);
    }

    private function grantProRole(string $npub): void
    {
        $user = $this->userRepository->findOneBy(['npub' => $npub]);
        if (!$user) {
            $user = new User();
            $user->setNpub($npub);
            $this->entityManager->persist($user);
        }
        if (!$user->isUpdatesProSubscriber()) {
            $user->addRole(RolesEnum::UPDATES_PRO->value);
            $this->entityManager->flush();
        }
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

