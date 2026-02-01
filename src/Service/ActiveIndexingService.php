<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ActiveIndexingSubscription;
use App\Entity\User;
use App\Enum\ActiveIndexingStatus;
use App\Enum\ActiveIndexingTier;
use App\Enum\RolesEnum;
use App\Repository\ActiveIndexingSubscriptionRepository;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrSigner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;

/**
 * Manages Active Indexing subscriptions
 */
class ActiveIndexingService
{
    private readonly string $recipientPubkeyHex;

    public function __construct(
        private readonly ActiveIndexingSubscriptionRepository $subscriptionRepository,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LNURLResolver $lnurlResolver,
        private readonly NostrSigner $nostrSigner,
        private readonly LoggerInterface $logger,
        string $recipientPubkey,
        private readonly string $recipientLud16,
    ) {
        // Convert npub to hex if needed
        if (str_starts_with($recipientPubkey, 'npub1')) {
            $key = new Key();
            $this->recipientPubkeyHex = $key->convertToHex($recipientPubkey);
        } else {
            $this->recipientPubkeyHex = $recipientPubkey;
        }
    }

    /**
     * Create or get existing subscription for a user
     */
    public function getOrCreateSubscription(string $npub, ActiveIndexingTier $tier): ActiveIndexingSubscription
    {
        $subscription = $this->subscriptionRepository->findByNpub($npub);

        if ($subscription) {
            // If expired, allow tier change
            if ($subscription->isExpired() || $subscription->getStatus() === ActiveIndexingStatus::PENDING) {
                $subscription->setTier($tier);
                $this->subscriptionRepository->save($subscription);
            }
            return $subscription;
        }

        $subscription = new ActiveIndexingSubscription($npub, $tier);
        $this->subscriptionRepository->save($subscription);

        return $subscription;
    }

    /**
     * Get subscription for an npub
     */
    public function getSubscription(string $npub): ?ActiveIndexingSubscription
    {
        return $this->subscriptionRepository->findByNpub($npub);
    }

    /**
     * Generate invoice for subscription payment
     *
     * @return array{bolt11: string, amount: int, subscription: ActiveIndexingSubscription}
     */
    public function createSubscriptionInvoice(string $npub, ActiveIndexingTier $tier): array
    {
        $subscription = $this->getOrCreateSubscription($npub, $tier);
        $amountSats = $tier->getPriceInSats();
        $amountMillisats = $amountSats * 1000;

        try {
            // Resolve DN's Lightning address
            $lnurlInfo = $this->lnurlResolver->resolve($this->recipientLud16);

            // For subscription payments, we create a regular LNURL invoice (not a zap)
            // This is more compatible with services like Geyser that may not support full NIP-57
            // We'll match the payment by the BOLT11 invoice string instead of requiring a zap receipt

            // Request invoice without zap request (regular LNURL-pay)
            $bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: null, // Skip zap request for compatibility
                lnurl: $lnurlInfo->bech32
            );

            // Store pending invoice
            $subscription->setPendingInvoiceBolt11($bolt11);
            $subscription->setStatus(ActiveIndexingStatus::PENDING);
            $this->subscriptionRepository->save($subscription);

            $this->logger->info('Created subscription invoice', [
                'npub' => $npub,
                'tier' => $tier->value,
                'amount_sats' => $amountSats,
            ]);

            return [
                'bolt11' => $bolt11,
                'amount' => $amountSats,
                'subscription' => $subscription,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create subscription invoice', [
                'npub' => $npub,
                'tier' => $tier->value,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Activate subscription after payment verification
     */
    public function activateSubscription(ActiveIndexingSubscription $subscription, string $zapReceiptEventId): void
    {
        $subscription->setZapReceiptEventId($zapReceiptEventId);
        $subscription->activate();
        $this->subscriptionRepository->save($subscription);

        // Grant role to user
        $this->grantActiveIndexingRole($subscription->getNpub());

        $this->logger->info('Activated subscription', [
            'npub' => $subscription->getNpub(),
            'tier' => $subscription->getTier()->value,
            'expires_at' => $subscription->getExpiresAt()?->format('Y-m-d H:i:s'),
            'zap_receipt' => $zapReceiptEventId,
        ]);
    }

    /**
     * Renew an existing subscription
     */
    public function renewSubscription(ActiveIndexingSubscription $subscription, string $zapReceiptEventId): void
    {
        $subscription->setZapReceiptEventId($zapReceiptEventId);
        $subscription->renew();
        $this->subscriptionRepository->save($subscription);

        // Ensure role is granted (in case it was removed)
        $this->grantActiveIndexingRole($subscription->getNpub());

        $this->logger->info('Renewed subscription', [
            'npub' => $subscription->getNpub(),
            'tier' => $subscription->getTier()->value,
            'new_expires_at' => $subscription->getExpiresAt()?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Transition expired subscriptions to grace period
     */
    public function processExpiredToGrace(): int
    {
        $subscriptions = $this->subscriptionRepository->findExpiredNeedingGraceTransition();
        $count = 0;

        foreach ($subscriptions as $subscription) {
            $subscription->setStatus(ActiveIndexingStatus::GRACE);
            $this->subscriptionRepository->save($subscription, false);

            $this->logger->info('Subscription moved to grace period', [
                'npub' => $subscription->getNpub(),
                'grace_ends_at' => $subscription->getGraceEndsAt()?->format('Y-m-d H:i:s'),
            ]);
            $count++;
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * Process subscriptions where grace period has ended
     */
    public function processGraceEnded(): int
    {
        $subscriptions = $this->subscriptionRepository->findGracePeriodEnded();
        $count = 0;

        foreach ($subscriptions as $subscription) {
            $subscription->setStatus(ActiveIndexingStatus::EXPIRED);
            $this->subscriptionRepository->save($subscription, false);

            // Remove role from user
            $this->revokeActiveIndexingRole($subscription->getNpub());

            $this->logger->info('Subscription expired, role removed', [
                'npub' => $subscription->getNpub(),
            ]);
            $count++;
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        return $count;
    }

    /**
     * Update relay configuration for a subscription
     */
    public function updateRelayConfiguration(
        ActiveIndexingSubscription $subscription,
        bool $useNip65Relays,
        ?array $customRelays = null
    ): void {
        $subscription->setUseNip65Relays($useNip65Relays);

        if (!$useNip65Relays && $customRelays !== null) {
            // Validate and clean relay URLs
            $validRelays = array_filter($customRelays, function ($relay) {
                return !empty($relay) && (
                    str_starts_with($relay, 'wss://') ||
                    str_starts_with($relay, 'ws://')
                );
            });
            $subscription->setCustomRelays(array_values($validRelays));
        }

        $this->subscriptionRepository->save($subscription);

        $this->logger->info('Updated relay configuration', [
            'npub' => $subscription->getNpub(),
            'use_nip65' => $useNip65Relays,
            'custom_relays' => $subscription->getCustomRelays(),
        ]);
    }

    /**
     * Update last fetched timestamp and article count
     */
    public function recordFetch(ActiveIndexingSubscription $subscription, int $articlesFound = 0): void
    {
        $subscription->setLastFetchedAt(new \DateTime());
        if ($articlesFound > 0) {
            $subscription->incrementArticlesIndexed($articlesFound);
        }
        $this->subscriptionRepository->save($subscription);
    }

    /**
     * Get all active subscriptions for fetching
     * @return ActiveIndexingSubscription[]
     */
    public function getActiveSubscriptions(): array
    {
        return $this->subscriptionRepository->findAllActive();
    }

    /**
     * Get subscriptions needing fetch
     * @return ActiveIndexingSubscription[]
     */
    public function getSubscriptionsNeedingFetch(int $minutesSinceLastFetch = 60): array
    {
        return $this->subscriptionRepository->findNeedingFetch($minutesSinceLastFetch);
    }

    /**
     * Get subscriptions with pending invoices
     * @return ActiveIndexingSubscription[]
     */
    public function getPendingSubscriptions(): array
    {
        return $this->subscriptionRepository->findWithPendingInvoices();
    }

    /**
     * Check if a user has an active subscription
     */
    public function hasActiveSubscription(string $npub): bool
    {
        $subscription = $this->subscriptionRepository->findByNpub($npub);
        return $subscription !== null && $subscription->isActive();
    }

    /**
     * Get statistics for dashboard
     */
    public function getStatistics(): array
    {
        return $this->subscriptionRepository->getStatistics();
    }

    /**
     * Cancel a pending subscription (user didn't pay)
     */
    public function cancelPendingSubscription(string $npub): void
    {
        $subscription = $this->subscriptionRepository->findByNpub($npub);

        if (!$subscription) {
            throw new \RuntimeException('No subscription found for this user');
        }

        if ($subscription->getStatus() !== ActiveIndexingStatus::PENDING) {
            throw new \RuntimeException('Only pending subscriptions can be cancelled');
        }

        // Delete the pending subscription so user can try again
        $this->subscriptionRepository->remove($subscription);

        $this->logger->info('Cancelled pending subscription', [
            'npub' => $npub,
            'tier' => $subscription->getTier()->value,
        ]);
    }

    /**
     * Grant ROLE_ACTIVE_INDEXING to user
     */
    private function grantActiveIndexingRole(string $npub): void
    {
        $user = $this->userRepository->findOneBy(['npub' => $npub]);

        if (!$user) {
            // Create user if doesn't exist
            $user = new User();
            $user->setNpub($npub);
            $this->entityManager->persist($user);
        }

        if (!$user->isActiveIndexingSubscriber()) {
            $user->addRole(RolesEnum::ACTIVE_INDEXING->value);
            $this->entityManager->flush();
        }
    }

    /**
     * Revoke ROLE_ACTIVE_INDEXING from user
     */
    private function revokeActiveIndexingRole(string $npub): void
    {
        $user = $this->userRepository->findOneBy(['npub' => $npub]);

        if ($user && $user->isActiveIndexingSubscriber()) {
            $user->removeRole(RolesEnum::ACTIVE_INDEXING->value);
            $this->entityManager->flush();
        }
    }
}
