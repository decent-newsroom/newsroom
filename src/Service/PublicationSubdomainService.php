<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PublicationSubdomainSubscription;
use App\Entity\UnfoldSite;
use App\Enum\PublicationSubdomainStatus;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use App\Repository\UnfoldSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PublicationSubdomainService
{
    public function __construct(
        private readonly PublicationSubdomainSubscriptionRepository $repository,
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly LNURLResolver $lnurlResolver,
        private readonly string $baseDomain = 'decentnewsroom.com',
        private readonly string $recipientLud16 = '',
    ) {
    }

    public function getBaseDomain(): string
    {
        return $this->baseDomain;
    }

    /**
     * Get subscription by npub
     */
    public function getByNpub(string $npub): ?PublicationSubdomainSubscription
    {
        return $this->repository->findByNpub($npub);
    }

    /**
     * Check if subdomain is available
     */
    public function isSubdomainAvailable(string $subdomain): bool
    {
        // Check in subscriptions
        if (!$this->repository->isSubdomainAvailable($subdomain)) {
            return false;
        }
        // Also check in existing unfold sites
        $existingUnfold = $this->unfoldSiteRepository->findOneBy(['subdomain' => strtolower($subdomain)]);
        return $existingUnfold === null;
    }

    /**
     * Validate subdomain format and availability
     */
    public function validateSubdomain(string $subdomain): ?string
    {
        if (!PublicationSubdomainSubscription::isValidSubdomainFormat($subdomain)) {
            return 'Invalid format. Use only lowercase letters, numbers, and hyphens (2-50 characters).';
        }

        if (!$this->isSubdomainAvailable($subdomain)) {
            return 'This subdomain is already taken.';
        }

        return null;
    }

    /**
     * Create a new subscription
     */
    public function createSubscription(
        string $npub,
        string $subdomain,
        string $magazineCoordinate
    ): PublicationSubdomainSubscription {
        // Validate
        $error = $this->validateSubdomain($subdomain);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        // Check if user already has an active or pending subscription
        $existing = $this->repository->findByNpub($npub);
        if ($existing !== null &&
            ($existing->getStatus() === PublicationSubdomainStatus::ACTIVE ||
             $existing->getStatus() === PublicationSubdomainStatus::PENDING)) {
            throw new \RuntimeException('User already has an active or pending publication subdomain.');
        }

        $subscription = new PublicationSubdomainSubscription($npub, $subdomain, $magazineCoordinate);
        $this->repository->save($subscription);

        $this->logger->info('Publication subdomain subscription created', [
            'npub' => $npub,
            'subdomain' => $subdomain,
            'coordinate' => $magazineCoordinate,
        ]);

        return $subscription;
    }

    /**
     * Create invoice for subscription payment
     * @return array{bolt11: string, amount: int, subscription: PublicationSubdomainSubscription}
     */
    public function createInvoice(PublicationSubdomainSubscription $subscription): array
    {
        $amountSats = PublicationSubdomainSubscription::PRICE_SATS;
        $amountMillisats = $amountSats * 1000;

        if (empty($this->recipientLud16)) {
            throw new \RuntimeException('Payment recipient Lightning address not configured.');
        }

        try {
            $lnurlInfo = $this->lnurlResolver->resolve($this->recipientLud16);

            $bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: null,
                lnurl: $lnurlInfo->bech32
            );

            $subscription->setPendingInvoiceBolt11($bolt11);
            $subscription->setStatus(PublicationSubdomainStatus::PENDING);
            $this->repository->save($subscription);

            $this->logger->info('Created publication subdomain invoice', [
                'subdomain' => $subscription->getSubdomain(),
                'npub' => $subscription->getNpub(),
                'amount_sats' => $amountSats,
            ]);

            return [
                'bolt11' => $bolt11,
                'amount' => $amountSats,
                'subscription' => $subscription,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create publication subdomain invoice', [
                'subdomain' => $subscription->getSubdomain(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Activate subscription and create UnfoldSite entry
     */
    public function activateSubscription(PublicationSubdomainSubscription $subscription): void
    {
        $subscription->activate();
        $this->repository->save($subscription);

        // Create UnfoldSite entry
        $unfoldSite = new UnfoldSite();
        $unfoldSite->setSubdomain($subscription->getSubdomain());
        $unfoldSite->setCoordinate($subscription->getMagazineCoordinate());

        $this->entityManager->persist($unfoldSite);
        $this->entityManager->flush();

        $this->logger->info('Publication subdomain activated', [
            'subdomain' => $subscription->getSubdomain(),
            'npub' => $subscription->getNpub(),
        ]);
    }

    /**
     * Get all subscriptions with optional status filter
     */
    public function getAll(?PublicationSubdomainStatus $status = null): array
    {
        return $this->repository->findAllWithStatus($status);
    }

    /**
     * Search subscriptions
     */
    public function search(string $query): array
    {
        return $this->repository->search($query);
    }

    /**
     * Cancel/revoke a pending subscription to release the subdomain
     */
    public function cancelSubscription(PublicationSubdomainSubscription $subscription): void
    {
        if ($subscription->getStatus() !== PublicationSubdomainStatus::PENDING) {
            throw new \RuntimeException('Only pending subscriptions can be cancelled.');
        }

        $subdomain = $subscription->getSubdomain();
        $npub = $subscription->getNpub();

        // Update status to cancelled
        $subscription->setStatus(PublicationSubdomainStatus::CANCELLED);
        $this->repository->save($subscription);

        $this->logger->info('Publication subdomain subscription cancelled', [
            'subdomain' => $subdomain,
            'npub' => $npub,
        ]);
    }
}
