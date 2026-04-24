<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\VanityName;
use App\Enum\VanityNamePaymentType;
use App\Enum\VanityNameStatus;
use App\Repository\UserRelayListRepository;
use App\Repository\VanityNameRepository;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class VanityNameService
{
    private const CACHE_TTL = 300; // 5 minutes cache for NIP-05 response
    private const LOOKUP_TTL = 300; // 5 minutes cache for vanity/npub lookups

    private readonly string $recipientPubkeyHex;

    public function __construct(
        private readonly VanityNameRepository $repository,
        private readonly UserRelayListRepository $userRelayListRepository,
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
    public function getValidationError(string $name, ?string $npub = null): ?string
    {
        if (!VanityName::isValidFormat($name)) {
            return 'Invalid format. Only letters (a-z), numbers (0-9), dash (-), underscore (_), and period (.) are allowed. Length must be 2-50 characters.';
        }

        if (VanityName::isReserved($name)) {
            return 'This name is reserved and cannot be registered.';
        }

        $existing = $this->repository->findByVanityName($name);
        if ($existing !== null) {
            $status = $existing->getStatus()->value;
            if (in_array($status, ['released', 'expired'], true)) {
                // Allow re-claim only if npub matches
                if ($npub !== null && $existing->getNpub() === $npub) {
                    return null; // allow
                } else {
                    return 'This name is already taken (previously released/expired, but only the original owner can reclaim).';
                }
            } else {
                return 'This name is already taken.';
            }
        }
        return null;
    }

    /**
     * Reserve a vanity name for a user (creates pending record)
     */
    public function reserve(string $npub, string $name, VanityNamePaymentType $paymentType): VanityName
    {
        $validationError = $this->getValidationError($name, $npub);
        if ($validationError !== null) {
            throw new \InvalidArgumentException($validationError);
        }

        // Check if user already has a vanity name
        $existing = $this->repository->findByNpub($npub);
        if ($existing !== null && $existing->getStatus() !== VanityNameStatus::RELEASED) {
            throw new \RuntimeException('User already has an active or pending vanity name.');
        }

        // If the name exists and is released/expired and npub matches, reuse the record
        $existingName = $this->repository->findByVanityName($name);
        if ($existingName !== null && in_array($existingName->getStatus()->value, ['released', 'expired'], true) && $existingName->getNpub() === $npub) {
            // Reset status and update payment type
            $existingName->setPaymentType($paymentType);
            $existingName->setExpiresAt(null);
            $existingName->setPendingInvoiceBolt11(null);
            // Free and admin grants are activated immediately; others stay pending
            if (in_array($paymentType, [VanityNamePaymentType::ADMIN_GRANTED, VanityNamePaymentType::FREE], true)) {
                $existingName->activate();
            } else {
                $existingName->setStatus(VanityNameStatus::PENDING);
            }
            $this->repository->save($existingName);
            $this->clearNip05Cache();
            $this->logger->info('Vanity name re-claimed by original owner', [
                'vanityName' => $name,
                'npub' => $npub,
                'paymentType' => $paymentType->value,
            ]);
            return $existingName;
        }

        // Convert npub to hex
        $key = new Key();
        $pubkeyHex = $key->convertToHex($npub);
        $vanityName = new VanityName($name, $npub, $pubkeyHex, $paymentType);

        // Free registrations and admin grants are activated immediately
        if (in_array($paymentType, [VanityNamePaymentType::ADMIN_GRANTED, VanityNamePaymentType::FREE], true)) {
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
        $cacheKey = 'vanity_active_by_name_' . strtolower($name);
        try {
            return $this->appCache->get($cacheKey, function (ItemInterface $item) use ($name) {
                $item->expiresAfter(self::LOOKUP_TTL);
                return $this->repository->findActiveByVanityName($name);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Cache unavailable for vanity name lookup, falling back to database', ['name' => $name, 'error' => $e->getMessage()]);
            return $this->repository->findActiveByVanityName($name);
        }
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
        $cacheKey = 'vanity_active_by_npub_' . strtolower($npub);
        try {
            return $this->appCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(self::LOOKUP_TTL);
                return $this->repository->findActiveByNpub($npub);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Cache unavailable for vanity npub lookup, falling back to database', ['npub' => $npub, 'error' => $e->getMessage()]);
            return $this->repository->findActiveByNpub($npub);
        }
    }

    /**
     * Find active vanity name by pubkey hex
     */
    public function getActiveByPubkeyHex(string $pubkeyHex): ?VanityName
    {
        $cacheKey = 'vanity_active_by_pubkey_' . strtolower($pubkeyHex);
        try {
            return $this->appCache->get($cacheKey, function (ItemInterface $item) use ($pubkeyHex) {
                $item->expiresAfter(self::LOOKUP_TTL);
                return $this->repository->findActiveByPubkeyHex($pubkeyHex);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Cache unavailable for vanity pubkey lookup, falling back to database', ['pubkeyHex' => $pubkeyHex, 'error' => $e->getMessage()]);
            return $this->repository->findActiveByPubkeyHex($pubkeyHex);
        }
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
     * Get NIP-05 response data (cached).
     * Includes both ACTIVE and PENDING names so that any registration is
     * immediately resolvable regardless of activation state.
     */
    public function getNip05Response(?string $name = null): array
    {
        $cacheKey = 'nip05_response' . ($name !== null ? '_' . strtolower($name) : '_all');

        try {
            return $this->appCache->get($cacheKey, function (ItemInterface $item) use ($name) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->buildNip05Response($name);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Cache unavailable for NIP-05 response, falling back to database', ['name' => $name, 'error' => $e->getMessage()]);
            return $this->buildNip05Response($name);
        }
    }

    private function buildNip05Response(?string $name): array
    {
        $names = [];
        $relays = [];

        if ($name !== null) {
            $vanityName = $this->repository->findActiveByVanityName($name);
            if ($vanityName !== null) {
                $names[$vanityName->getVanityName()] = $vanityName->getPubkeyHex();
                $relayList = $this->userRelayListRepository->findByPubkey($vanityName->getPubkeyHex());
                $resolved = $this->resolveRelays($vanityName, $relayList);
                if (!empty($resolved)) {
                    $relays[$vanityName->getPubkeyHex()] = $resolved;
                }
            }
        } else {
            $activeNames = $this->repository->findAllActive();

            // Batch-load NIP-65 relay lists for all pubkeys in one query
            $pubkeys = array_map(fn($v) => $v->getPubkeyHex(), $activeNames);
            $relayLists = $this->userRelayListRepository->findByPubkeys($pubkeys);

            foreach ($activeNames as $vanityName) {
                $names[$vanityName->getVanityName()] = $vanityName->getPubkeyHex();
                $resolved = $this->resolveRelays($vanityName, $relayLists[$vanityName->getPubkeyHex()] ?? null);
                if (!empty($resolved)) {
                    $relays[$vanityName->getPubkeyHex()] = $resolved;
                }
            }
        }

        $response = ['names' => $names];
        if (!empty($relays)) {
            $response['relays'] = $relays;
        }

        return $response;
    }

    /**
     * Resolve relay URLs for a vanity name.
     * Uses the manually set relays on the VanityName if present,
     * otherwise falls back to the user's NIP-65 write relays.
     *
     * @return string[]
     */
    private function resolveRelays(VanityName $vanityName, ?\App\Entity\UserRelayList $relayList = null): array
    {
        // Prefer manually configured relays on the vanity name record
        if ($vanityName->getRelays() !== null && !empty($vanityName->getRelays())) {
            return $vanityName->getRelays();
        }

        // Fall back to the user's NIP-65 write relays (or all relays if no writes)
        if ($relayList !== null) {
            $writeRelays = $relayList->getWriteRelays();
            return !empty($writeRelays) ? $writeRelays : $relayList->getAllRelays();
        }

        return [];
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
     * Get server domain for NIP-05 identifiers.
     * Comes from BASE_DOMAIN env var (falls back to SERVER_NAME via base_domain parameter).
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

