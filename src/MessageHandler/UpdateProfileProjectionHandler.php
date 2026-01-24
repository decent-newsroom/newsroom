<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\UpdateProfileProjectionMessage;
use App\Repository\UserEntityRepository;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles async profile projection updates.
 *
 * Updates the User entity projection from:
 * 1. Redis cache (metadata from kind:0, relays from kind:10002)
 * 2. Raw Event table (future: when profile events are persisted locally)
 *
 * This handler is idempotent and order-independent.
 */
#[AsMessageHandler]
class UpdateProfileProjectionHandler
{
    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UpdateProfileProjectionMessage $message): void
    {
        $pubkeyHex = $message->getPubkeyHex();

        $this->logger->info('Processing profile projection update', [
            'pubkey' => substr($pubkeyHex, 0, 8) . '...'
        ]);

        try {
            // Convert to npub for user lookup
            $npub = NostrKeyUtil::hexToNpub($pubkeyHex);

            // Get or create user
            $user = $this->userRepository->findOneBy(['npub' => $npub]);

            if (!$user) {
                $this->logger->info('Creating new user during projection update', [
                    'npub' => $npub
                ]);
                $user = new User();
                $user->setNpub($npub);
                $this->entityManager->persist($user);
            }

            // Update metadata facet from Redis
            $updated = $this->updateMetadataFacet($user, $pubkeyHex);

            // Update relay list facet from Redis
            $updated = $this->updateRelayListFacet($user, $pubkeyHex) || $updated;

            if ($updated) {
                $this->entityManager->flush();
                $this->logger->info('Profile projection updated successfully', [
                    'npub' => $npub
                ]);
            } else {
                $this->logger->debug('No profile data available to update', [
                    'npub' => $npub
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Failed to update profile projection', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            // Don't rethrow - we don't want to retry indefinitely
        }
    }

    /**
     * Update metadata facet (kind:0) from Redis cache
     */
    private function updateMetadataFacet(User $user, string $pubkeyHex): bool
    {
        try {
            $metadata = $this->redisCacheService->getMetadata($pubkeyHex);

            if ($metadata === null) {
                return false;
            }

            // Update user fields from metadata
            if (isset($metadata->display_name)) {
                $user->setDisplayName($this->sanitizeStringValue($metadata->display_name));
            }
            if (isset($metadata->name)) {
                $user->setName($this->sanitizeStringValue($metadata->name));
            }
            if (isset($metadata->nip05)) {
                $user->setNip05($this->sanitizeStringValue($metadata->nip05));
            }
            if (isset($metadata->about)) {
                $user->setAbout($this->sanitizeStringValue($metadata->about));
            }
            if (isset($metadata->website)) {
                $user->setWebsite($this->sanitizeStringValue($metadata->website));
            }
            if (isset($metadata->picture)) {
                $user->setPicture($this->sanitizeStringValue($metadata->picture));
            }
            if (isset($metadata->banner)) {
                $user->setBanner($this->sanitizeStringValue($metadata->banner));
            }
            if (isset($metadata->lud16)) {
                $user->setLud16($this->sanitizeStringValue($metadata->lud16));
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update metadata facet', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Update relay list facet (kind:10002) from Redis cache
     *
     * Note: Currently just fetches from Redis. In the future, this will also
     * read from persisted kind:10002 events in the Event table.
     */
    private function updateRelayListFacet(User $user, string $pubkeyHex): bool
    {
        try {
            $relays = $this->redisCacheService->getRelays($pubkeyHex);

            if (empty($relays)) {
                return false;
            }

            // Note: Relay data is typically not persisted to User entity columns
            // but could be stored in a separate relation if needed
            // For now, we just log that we have relay data
            $this->logger->debug('Retrieved relay list', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'relay_count' => is_array($relays) ? count($relays) : 0
            ]);

            return false; // Not persisting relays to DB yet
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update relay list facet', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Sanitize metadata value to ensure it's a string or null
     */
    private function sanitizeStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            $stringValues = array_filter($value, fn($item) => is_scalar($item));
            $stringValues = array_map(fn($item) => (string) $item, $stringValues);

            if (empty($stringValues)) {
                return null;
            }

            return implode(', ', $stringValues);
        }

        if (is_object($value)) {
            return null;
        }

        return (string) $value;
    }
}
