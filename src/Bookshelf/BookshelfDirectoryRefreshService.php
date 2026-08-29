<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use DecentNewsroom\BookshelfBundle\Enum\KindsEnum;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use Psr\Log\LoggerInterface;

/**
 * Refreshes the signed My Books directory from the local relay on page load.
 */
final class BookshelfDirectoryRefreshService
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly GenericEventProjector $eventProjector,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function refreshForUser(string $pubkey): void
    {
        $coordinate = sprintf(
            '%d:%s:%s',
            KindsEnum::DIRECTORY->value,
            strtolower($pubkey),
            BookshelfDirectoryService::IDENTIFIER,
        );

        try {
            $events = $this->nostrClient->getEventsByCoordinates([$coordinate]);
            $event = $events[$coordinate] ?? null;
            if ($event !== null) {
                // The projector applies NIP-01 replaceable-event ordering, so an
                // older relay copy cannot replace the local directory.
                $this->eventProjector->projectEventFromNostrEvent($event, 'local');
            }
        } catch (\Throwable $exception) {
            // The locally projected directory remains usable when the relay is
            // unavailable; this refresh must never make My Books unavailable.
            $this->logger->warning('Could not refresh My Books directory from the local relay.', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
