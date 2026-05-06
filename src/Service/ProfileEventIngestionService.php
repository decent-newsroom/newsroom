<?php

namespace App\Service;

use App\Entity\Event;
use Psr\Log\LoggerInterface;

/**
 * Service to handle profile-related event ingestion.
 *
 * When profile events (kind:0 metadata, kind:10002 relay list) are ingested,
 * this service triggers async projection updates via the throttled dispatcher.
 */
class ProfileEventIngestionService
{
    private const PROFILE_KINDS = [
        0,      // Metadata (NIP-01)
        10002,  // Relay list (NIP-65)
    ];

    public function __construct(
        private readonly ProfileUpdateDispatcher $profileUpdateDispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Check if an event is profile-related and trigger projection update if needed.
     *
     * Call this after persisting a raw event to the Event table.
     */
    public function handleEventIngestion(Event $event): void
    {
        if (!$this->isProfileEvent($event)) {
            return;
        }

        try {
            $pubkeyHex = $event->getPubkey();

            $this->logger->debug('Profile event ingested, triggering projection update', [
                'kind' => $event->getKind(),
                'pubkey' => substr($pubkeyHex, 0, 8) . '...'
            ]);

            $this->profileUpdateDispatcher->dispatch($pubkeyHex);
        } catch (\Exception $e) {
            $this->logger->error('Failed to trigger profile projection update after ingestion', [
                'event_id' => $event->getId(),
                'kind' => $event->getKind(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Batch handle multiple ingested events.
     *
     * More efficient when ingesting many events at once.
     */
    public function handleBatchEventIngestion(array $events): void
    {
        $pubkeysToUpdate = [];

        foreach ($events as $event) {
            if (!$event instanceof Event) {
                continue;
            }

            if ($this->isProfileEvent($event)) {
                $pubkeysToUpdate[$event->getPubkey()] = true;
            }
        }

        if (empty($pubkeysToUpdate)) {
            return;
        }

        $pubkeys = array_keys($pubkeysToUpdate);

        $this->logger->info('Profile events ingested in batch, triggering projection updates', [
            'pubkey_count' => count($pubkeys)
        ]);

        $queued = $this->profileUpdateDispatcher->dispatchBatch($pubkeys);

        $this->logger->debug('Batch profile dispatch result', [
            'requested' => count($pubkeys),
            'queued' => $queued,
            'throttled' => count($pubkeys) - $queued,
        ]);
    }

    /**
     * Check if an event is profile-related.
     */
    private function isProfileEvent(Event $event): bool
    {
        return in_array($event->getKind(), self::PROFILE_KINDS, true);
    }
}
