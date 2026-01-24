<?php

namespace App\MessageHandler;

use App\Message\FetchMediaEventsMessage;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchMediaEventsHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(FetchMediaEventsMessage $message): void
    {
        $hashtags = $message->getHashtags();
        $kinds = $message->getKinds();

        $this->logger->info('Fetching media events for hashtags', [
            'hashtag_count' => count($hashtags),
            'kinds' => $kinds
        ]);

        try {
            // Fetch media events from Nostr relays
            $rawEvents = $this->nostrClient->getMediaEventsByHashtags($hashtags, $kinds);

            $this->logger->info('Fetched media events from relays', [
                'count' => count($rawEvents)
            ]);

            // Save each event to the database
            $savedCount = 0;
            foreach ($rawEvents as $rawEvent) {
                try {
                    $this->nostrClient->saveMediaEvent($rawEvent);
                    $savedCount++;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to save media event', [
                        'event_id' => $rawEvent->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Flush all changes at once
            $this->eventRepository->flush();

            $this->logger->info('Saved media events to database', [
                'saved' => $savedCount,
                'total' => count($rawEvents)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error fetching media events', [
                'hashtags' => $hashtags,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
