<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\VanityName;
use App\Enum\VanityNamePaymentType;
use App\Enum\VanityNameStatus;
use App\Repository\VanityNameRepository;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class VanityNameService
{
    private const CACHE_TTL = 300; // 5 minutes cache for NIP-05 response

    private readonly string $recipientPubkeyHex;

    public function __construct(
        private readonly VanityNameRepository $repository,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $appCache,
        private readonly LNURLResolver $lnurlResolver,
        private readonly string $serverDomain = 'localhost',
        private readonly string $recipientLud16 = '',
    ) {
    }

    /**
     * Check if a vanity name is available for registration
     */
    public function isAvailable(string $name): bool
    {
        // Check format validity
        if (!VanityName::isValidFormat($name)) {
            return false;
        }

        // Check reserved names
        if (VanityName::isReserved($name)) {
            return false;
        }

        // Check database
        return $this->repository->isAvailable($name);
    }

    /**
     * Get validation error for a vanity name, or null if valid
     */
    public function getValidationError(string $name): ?string
    {
        if (!VanityName::isValidFormat($name)) {
            return 'Invalid format. Only letters (a-z), numbers (0-9), dash (-), underscore (_), and period (.) are allowed. Length must be 2-50 characters.';
        }

        if (VanityName::isReserved($name)) {
            return 'This name is reserved and cannot be registered.';
        }

        if (!$this->repository->isAvailable($name)) {
            return 'This name is already taken.';
        }

        return null;
    }

    /**
     * Reserve a vanity name for a user (creates pending record)
     */
    public function reserve(string $npub, string $name, VanityNamePaymentType $paymentType): VanityName
    {
        $validationError = $this->getValidationError($name);
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
        }

        // Check if user already has a vanity name
        $existing = $this->repository->findByNpub($npub);
        if ($existing !== null && $existing->getStatus() !== VanityNameStatus::RELEASED) {
            throw new \RuntimeException('User already has an active or pending vanity name.');
        }

        // Convert npub to hex
        $key = new Key();
        $pubkeyHex = $key->convertToHex($npub);

        $vanityName = new VanityName($name, $npub, $pubkeyHex, $paymentType);

        // Admin grants are activated immediately
        if ($paymentType === VanityNamePaymentType::ADMIN_GRANTED) {
            $vanityName->activate();
        }

        $this->repository->save($vanityName);
        $this->clearNip05Cache();

        $this->logger->info('Vanity name reserved', [
            'vanityName' => $name,
            'npub' => $npub,
            'paymentType' => $paymentType->value,
        ]);

        return $vanityName;
    }

    /**
     * Create invoice for vanity name payment
     *
     * @return array{bolt11: string, amount: int, vanityName: VanityName}
     */
    public function createInvoice(VanityName $vanityName): array
    {
        $amountSats = $vanityName->getPaymentType()->getPriceInSats();
        $amountMillisats = $amountSats * 1000;

        if (empty($this->recipientLud16)) {
            throw new \RuntimeException('Payment recipient Lightning address not configured.');
        }

        try {
            // Resolve the Lightning address
            $lnurlInfo = $this->lnurlResolver->resolve($this->recipientLud16);

            // Request invoice without zap request (regular LNURL-pay)
            $bolt11 = $this->lnurlResolver->requestInvoice(
                callback: $lnurlInfo->callback,
                amountMillisats: $amountMillisats,
                nostrEvent: null, // Skip zap request for compatibility
                lnurl: $lnurlInfo->bech32
            );

            // Store pending invoice
            $vanityName->setPendingInvoiceBolt11($bolt11);
            $vanityName->setStatus(VanityNameStatus::PENDING);
            $this->repository->save($vanityName);

            $this->logger->info('Created vanity name invoice', [
                'vanityName' => $vanityName->getVanityName(),
                'npub' => $vanityName->getNpub(),
                'amount_sats' => $amountSats,
            ]);

            return [
                'bolt11' => $bolt11,
                'amount' => $amountSats,
                'vanityName' => $vanityName,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to create vanity name invoice', [
                'vanityName' => $vanityName->getVanityName(),
                'npub' => $vanityName->getNpub(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Reserve and create invoice for vanity name in one step
     *
     * @return array{bolt11: string, amount: int, vanityName: VanityName}
     */
    public function reserveWithInvoice(string $npub, string $name, VanityNamePaymentType $paymentType): array
    {
        $vanityName = $this->reserve($npub, $name, $paymentType);

        // Admin grants don't need invoices
        if ($paymentType === VanityNamePaymentType::ADMIN_GRANTED) {
            return [
                'bolt11' => '',
                'amount' => 0,
                'vanityName' => $vanityName,
            ];
        }

        return $this->createInvoice($vanityName);
    }

    /**
     * Check if user has a pending vanity name
     */
    public function hasPendingVanityName(string $npub): bool
    {
        $vanityName = $this->repository->findByNpub($npub);
        return $vanityName !== null && $vanityName->getStatus() === VanityNameStatus::PENDING;
    }

    /**
     * Check if user has an active vanity name
     */
    public function hasActiveVanityName(string $npub): bool
    {
        $vanityName = $this->repository->findByNpub($npub);
        return $vanityName !== null && $vanityName->getStatus() === VanityNameStatus::ACTIVE;
    }

    /**
     * Cancel a pending vanity name reservation
     */
    public function cancelPending(string $npub): void
    {
        $vanityName = $this->repository->findByNpub($npub);

        if ($vanityName === null) {
            throw new \RuntimeException('No vanity name found for this user.');
        }

        if ($vanityName->getStatus() !== VanityNameStatus::PENDING) {
            throw new \RuntimeException('Only pending vanity names can be cancelled.');
        }

        $vanityName->release();
        $this->repository->save($vanityName);
        $this->clearNip05Cache();

        $this->logger->info('Pending vanity name cancelled', [
            'vanityName' => $vanityName->getVanityName(),
            'npub' => $npub,
        ]);
    }

    /**
     * Activate a vanity name after payment
     */
    public function activate(VanityName $vanityName): void
    {
        $vanityName->activate();
        $this->repository->save($vanityName);
        $this->clearNip05Cache();

        $this->logger->info('Vanity name activated', [
            'vanityName' => $vanityName->getVanityName(),
            'npub' => $vanityName->getNpub(),
        ]);
    }

    /**
     * Activate a vanity name by its name
     */
    public function activateByName(string $name): void
    {
        $vanityName = $this->repository->findByVanityName($name);
        if ($vanityName === null) {
            throw new \RuntimeException('Vanity name not found: ' . $name);
        }
        $this->activate($vanityName);
    }

    /**
     * Suspend a vanity name
     */
    public function suspend(VanityName $vanityName): void
    {
        $vanityName->suspend();
        $this->repository->save($vanityName);
        $this->clearNip05Cache();

        $this->logger->info('Vanity name suspended', [
            'vanityName' => $vanityName->getVanityName(),
            'npub' => $vanityName->getNpub(),
        ]);
    }

    /**
     * Release a vanity name
     */
    public function release(VanityName $vanityName): void
    {
        $vanityName->release();
        $this->repository->save($vanityName);
        $this->clearNip05Cache();

        $this->logger->info('Vanity name released', [
            'vanityName' => $vanityName->getVanityName(),
            'npub' => $vanityName->getNpub(),
        ]);
    }

    /**
     * Find vanity name by name
     */
    public function getByVanityName(string $name): ?VanityName
    {
        return $this->repository->findByVanityName($name);
    }

    /**
     * Find active vanity name by name
     */
    public function getActiveByVanityName(string $name): ?VanityName
    {
        return $this->repository->findActiveByVanityName($name);
    }

    /**
     * Find vanity name by npub
     */
    public function getByNpub(string $npub): ?VanityName
    {
        return $this->repository->findByNpub($npub);
    }

    /**
     * Find active vanity name by npub
     */
    public function getActiveByNpub(string $npub): ?VanityName
    {
        return $this->repository->findActiveByNpub($npub);
    }

    /**
     * Find active vanity name by pubkey hex
     */
    public function getActiveByPubkeyHex(string $pubkeyHex): ?VanityName
    {
        return $this->repository->findActiveByPubkeyHex($pubkeyHex);
    }

    /**
     * Get all active vanity names
     * @return VanityName[]
     */
    public function getAllActive(): array
    {
        return $this->repository->findAllActive();
    }

    /**
     * Get all vanity names, optionally filtered by status
     * @return VanityName[]
     */
    public function getAll(?VanityNameStatus $status = null): array
    {
        return $this->repository->findAllWithStatus($status);
    }

    /**
     * Get NIP-05 response data (cached)
     */
    public function getNip05Response(?string $name = null): array
    {
        $cacheKey = 'nip05_response' . ($name !== null ? '_' . strtolower($name) : '_all');

        return $this->appCache->get($cacheKey, function (ItemInterface $item) use ($name) {
            $item->expiresAfter(self::CACHE_TTL);

            $names = [];
            $relays = [];

            if ($name !== null) {
                // Single name lookup
                $vanityName = $this->repository->findActiveByVanityName($name);
                if ($vanityName !== null) {
                    $names[$vanityName->getVanityName()] = $vanityName->getPubkeyHex();
                    if ($vanityName->getRelays() !== null && !empty($vanityName->getRelays())) {
                        $relays[$vanityName->getPubkeyHex()] = $vanityName->getRelays();
                    }
                }
            } else {
                // All active names
                $activeNames = $this->repository->findAllActive();
                foreach ($activeNames as $vanityName) {
                    $names[$vanityName->getVanityName()] = $vanityName->getPubkeyHex();
                    if ($vanityName->getRelays() !== null && !empty($vanityName->getRelays())) {
                        $relays[$vanityName->getPubkeyHex()] = $vanityName->getRelays();
                    }
                }
            }

            $response = ['names' => $names];
            if (!empty($relays)) {
                $response['relays'] = $relays;
            }

            return $response;
        });
    }

    /**
     * Clear the NIP-05 cache
     */
    public function clearNip05Cache(): void
    {
        $this->appCache->delete('nip05_response_all');
        // Note: Individual name caches will expire naturally
    }

    /**
     * Process expired vanity names
     */
    public function processExpired(): int
    {
        $expired = $this->repository->findExpired();
        $count = 0;

        foreach ($expired as $vanityName) {
            $this->release($vanityName);
            $count++;

            $this->logger->info('Vanity name expired and released', [
                'vanityName' => $vanityName->getVanityName(),
                'npub' => $vanityName->getNpub(),
            ]);
        }

        return $count;
    }

    /**
     * Get server domain for NIP-05 identifiers
     */
    public function getServerDomain(): string
    {
        return $this->serverDomain;
    }

    /**
     * Search vanity names
     * @return VanityName[]
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->repository->search($query, $limit);
    }

    /**
     * Renew a subscription vanity name
     */
    public function renew(VanityName $vanityName): void
    {
        if ($vanityName->getPaymentType() !== VanityNamePaymentType::SUBSCRIPTION) {
            throw new \RuntimeException('Only subscription vanity names can be renewed.');
        }

        $durationDays = $vanityName->getPaymentType()->getDurationInDays();
        $currentExpiry = $vanityName->getExpiresAt() ?? new \DateTime();

        // If already expired, start from now
        if ($currentExpiry < new \DateTime()) {
            $currentExpiry = new \DateTime();
        }

        $newExpiry = (clone $currentExpiry)->modify("+{$durationDays} days");
        $vanityName->setExpiresAt($newExpiry);
        $vanityName->setStatus(VanityNameStatus::ACTIVE);

        $this->repository->save($vanityName);
        $this->clearNip05Cache();

        $this->logger->info('Vanity name renewed', [
            'vanityName' => $vanityName->getVanityName(),
            'npub' => $vanityName->getNpub(),
            'newExpiresAt' => $newExpiry->format('Y-m-d H:i:s'),
        ]);
    }
}

