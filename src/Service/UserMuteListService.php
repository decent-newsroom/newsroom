<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Psr\Log\LoggerInterface;

/**
 * Reads the logged-in user's personal mute list (kind 10000, NIP-51)
 * from the local database and extracts muted pubkeys.
 *
 * The mute list event is synced on login via SyncUserEventsHandler,
 * so it is expected to be present in the Event table for active users.
 */
class UserMuteListService
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get the hex pubkeys the user has muted via their kind 10000 event.
     *
     * @param string $pubkeyHex The logged-in user's hex pubkey
     * @return string[] Muted hex pubkeys (from "p" tags)
     */
    public function getMutedPubkeys(string $pubkeyHex): array
    {
        try {
            $event = $this->eventRepository->findLatestByPubkeyAndKind(
                $pubkeyHex,
                KindsEnum::MUTE_LIST->value,
            );

            if ($event === null) {
                return [];
            }

            $muted = [];
            foreach ($event->getTags() as $tag) {
                if (is_array($tag) && isset($tag[0], $tag[1]) && $tag[0] === 'p') {
                    $muted[] = $tag[1];
                }
            }

            return array_values(array_unique($muted));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to read user mute list', [
                'pubkey' => $pubkeyHex,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}

