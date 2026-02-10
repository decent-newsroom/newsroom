<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class UserMetadataSyncService
{
    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserEntityRepository $userRepository,
        private readonly AuthorRelayService $authorRelayService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sync metadata for a single user from Redis to database fields
     */
    public function syncUser(User $user): bool
    {
        try {
            $npub = $user->getNpub();

            // Convert npub to hex pubkey
            if (!NostrKeyUtil::isNpub($npub)) {
                $this->logger->warning("Invalid npub format for user: {$npub}");
                return false;
            }

            $hexPubkey = NostrKeyUtil::npubToHex($npub);
            $metadata = $this->redisCacheService->getMetadata($hexPubkey);

            if ($metadata === null) {
                return false;
            }

            $this->updateUserFromMetadata($user, $metadata);

            // Also sync relays if missing or empty
            $storedRelays = $user->getRelays();
            $hasValidRelays = !empty($storedRelays['write']) || !empty($storedRelays['all']);

            if (!$hasValidRelays) {
                // Use non-blocking mode to avoid slowing down login
                $relays = $this->authorRelayService->getAuthorRelays($hexPubkey, false);
                if (!empty($relays['all'])) {
                    $user->setRelays($relays);
                    $this->logger->debug("Synced relays for user: {$npub}", [
                        'relay_count' => count($relays['all'])
                    ]);
                }
            }

            $this->entityManager->flush();

            $this->logger->info("Synced metadata for user: {$npub}");
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error syncing metadata for user {$user->getNpub()}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync metadata for all users in the database
     * @param int $batchSize Number of users to process in each batch
     * @return array Statistics about the sync operation
     */
    public function syncAllUsers(int $batchSize = 50): array
    {
        $stats = [
            'total' => 0,
            'synced' => 0,
            'no_metadata' => 0,
            'errors' => 0
        ];

        try {
            $users = $this->userRepository->findAll();
            $stats['total'] = count($users);

            $this->logger->info("Starting metadata sync for {$stats['total']} users");

            $count = 0;
            foreach ($users as $user) {
                try {
                    $npub = $user->getNpub();

                    // Convert npub to hex pubkey
                    if (!NostrKeyUtil::isNpub($npub)) {
                        $stats['errors']++;
                        $this->logger->warning("Invalid npub format for user: {$npub}");
                        continue;
                    }

                    $hexPubkey = NostrKeyUtil::npubToHex($npub);
                    $metadata = $this->redisCacheService->getMetadata($hexPubkey);

                    if ($metadata === null) {
                        $stats['no_metadata']++;
                        continue;
                    }

                    $this->updateUserFromMetadata($user, $metadata);
                    $stats['synced']++;
                    $count++;

                    // Flush in batches
                    if ($count % $batchSize === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                        $this->logger->info("Synced {$count}/{$stats['total']} users");
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->logger->error("Error syncing user {$user->getNpub()}: " . $e->getMessage());
                }
            }

            // Flush remaining
            $this->entityManager->flush();
            $this->logger->info("Completed metadata sync. Synced: {$stats['synced']}, No metadata: {$stats['no_metadata']}, Errors: {$stats['errors']}");
        } catch (\Exception $e) {
            $this->logger->error("Error in syncAllUsers: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Update user entity fields from metadata object
     */
    private function updateUserFromMetadata(User $user, object $metadata): void
    {
        // Handle UserMetadata DTO
        if ($metadata instanceof \App\Dto\UserMetadata) {
            if ($metadata->displayName !== null) {
                $user->setDisplayName($metadata->displayName);
            }
            if ($metadata->name !== null) {
                $user->setName($metadata->name);
            }
            if ($metadata->getNip05() !== null) {
                $user->setNip05($metadata->getNip05());
            }
            if ($metadata->about !== null) {
                $user->setAbout($metadata->about);
            }
            if ($metadata->getWebsite() !== null) {
                $user->setWebsite($metadata->getWebsite());
            }
            if ($metadata->picture !== null) {
                $user->setPicture($metadata->picture);
            }
            if ($metadata->banner !== null) {
                $user->setBanner($metadata->banner);
            }
            if ($metadata->getLightningAddress() !== null) {
                $user->setLud16($metadata->getLightningAddress());
            }
            return;
        }

        // Handle legacy stdClass format
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
    }

    /**
     * Sanitize metadata value to ensure it's a string or null
     * Handles cases where metadata might be an array or other type
     */
    private function sanitizeStringValue(mixed $value): ?string
    {
        // If already null, return null
        if ($value === null) {
            return null;
        }

        // If it's an array, implode to keep all values
        if (is_array($value)) {
            // If array is empty, return null
            if (empty($value)) {
                return null;
            }

            // Filter out non-scalar values and convert to strings
            $stringValues = array_filter($value, fn($item) => is_scalar($item));
            $stringValues = array_map(fn($item) => (string) $item, $stringValues);

            // If no valid values after filtering, return null
            if (empty($stringValues)) {
                $this->logger->warning("Metadata field contains array with no scalar values: " . json_encode($value));
                return null;
            }

            // Implode with comma separator
            return implode(', ', $stringValues);
        }

        // If it's an object, return null (can't use object as string)
        if (is_object($value)) {
            $this->logger->warning("Metadata field contains object: " . get_class($value));
            return null;
        }

        // Convert to string
        return (string) $value;
    }
}

