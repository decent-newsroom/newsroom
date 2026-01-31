<?php

namespace App\MessageHandler;

use App\Entity\User;
use App\Message\BatchUpdateProfileProjectionMessage;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrClient;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles batch profile projection updates by fetching metadata in a single batch request.
 *
 * This is more efficient than dispatching individual UpdateProfileProjectionMessage
 * as it makes a single relay call for multiple pubkeys.
 */
#[AsMessageHandler]
class BatchUpdateProfileProjectionHandler
{
    private const BATCH_SIZE = 25; // Process this many at a time to avoid timeouts

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(BatchUpdateProfileProjectionMessage $message): void
    {
        $pubkeys = $message->getPubkeyHexList();

        $this->logger->info('Processing batch profile projection update', [
            'count' => count($pubkeys)
        ]);

        $updated = 0;
        $created = 0;
        $failed = 0;

        // Process in smaller batches to avoid timeout issues
        $batches = array_chunk($pubkeys, self::BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            try {
                $this->processBatch($batch, $updated, $created, $failed);

                $this->logger->debug('Processed batch', [
                    'batch_index' => $batchIndex + 1,
                    'batch_size' => count($batch),
                    'total_batches' => count($batches)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to process batch', [
                    'batch_index' => $batchIndex + 1,
                    'error' => $e->getMessage()
                ]);
                $failed += count($batch);
            }
        }

        $this->logger->info('Batch profile projection complete', [
            'updated' => $updated,
            'created' => $created,
            'failed' => $failed,
            'total' => count($pubkeys)
        ]);
    }

    private function processBatch(array $pubkeys, int &$updated, int &$created, int &$failed): void
    {
        // Batch fetch metadata from relays (single network call)
        try {
            $metadataMap = $this->nostrClient->getMetadataForPubkeys($pubkeys, true);
        } catch (\Exception $e) {
            $this->logger->warning('Batch metadata fetch failed', [
                'error' => $e->getMessage(),
                'count' => count($pubkeys)
            ]);
            $metadataMap = [];
        }

        // Process each pubkey
        foreach ($pubkeys as $pubkeyHex) {
            try {
                $npub = NostrKeyUtil::hexToNpub($pubkeyHex);
                $user = $this->userRepository->findOneBy(['npub' => $npub]);

                if (!$user) {
                    $user = new User();
                    $user->setNpub($npub);
                    $this->entityManager->persist($user);
                    $created++;
                }

                // Update from batch-fetched metadata
                if (isset($metadataMap[$pubkeyHex])) {
                    $this->updateUserFromMetadata($user, $metadataMap[$pubkeyHex]);
                    $updated++;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to process pubkey in batch', [
                    'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                    'error' => $e->getMessage()
                ]);
                $failed++;
            }
        }

        // Flush all changes in a single transaction
        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush batch updates', [
                'error' => $e->getMessage()
            ]);
            $this->entityManager->clear();
            throw $e;
        }
    }

    private function updateUserFromMetadata(User $user, \stdClass $rawEvent): void
    {
        $metadata = json_decode($rawEvent->content ?? '{}');
        if (!$metadata || !is_object($metadata)) {
            return;
        }

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
            return empty($stringValues) ? null : implode(', ', $stringValues);
        }

        if (is_object($value)) {
            return null;
        }

        return (string) $value;
    }
}
