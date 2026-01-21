<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchHighlightsMessage;
use App\Service\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchHighlightsHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(FetchHighlightsMessage $message): void
    {
        $limit = $message->getLimit();

        $this->logger->info('Fetching highlights from Nostr relays', [
            'limit' => $limit
        ]);

        try {
            // Fetch highlights from Nostr relays
            $events = $this->nostrClient->getArticleHighlights($limit);

            $this->logger->info('Fetched highlights from relays', [
                'count' => count($events)
            ]);

            if (empty($events)) {
                $this->logger->debug('No highlights found from relays');
                return;
            }

            // Save highlights to database
            $saved = 0;
            $skipped = 0;

            foreach ($events as $nostrEvent) {
                try {
                    // Skip if event ID is missing
                    if (!isset($nostrEvent->id) || empty($nostrEvent->id)) {
                        $this->logger->warning('Skipping event without ID');
                        $skipped++;
                        continue;
                    }

                    // Check if event already exists
                    $existingEvent = $this->entityManager->getRepository(Event::class)->find($nostrEvent->id);
                    if ($existingEvent) {
                        $this->logger->debug('Event already exists, skipping', ['event_id' => $nostrEvent->id]);
                        $skipped++;
                        continue;
                    }

                    // Create new Event entity
                    $event = new Event();
                    $event->setId($nostrEvent->id);
                    $event->setEventId($nostrEvent->id);
                    $event->setKind($nostrEvent->kind ?? KindsEnum::HIGHLIGHTS->value);
                    $event->setPubkey($nostrEvent->pubkey ?? '');
                    $event->setContent($nostrEvent->content ?? '');
                    $event->setCreatedAt($nostrEvent->created_at ?? time());
                    $event->setTags($nostrEvent->tags ?? []);
                    $event->setSig($nostrEvent->sig ?? '');

                    $this->entityManager->persist($event);
                    $saved++;

                    // Flush in batches of 20 to improve performance
                    if ($saved % 20 === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }

                    $this->logger->debug('Prepared highlight for save', [
                        'event_id' => $nostrEvent->id,
                        'kind' => $nostrEvent->kind,
                        'pubkey' => substr($nostrEvent->pubkey ?? '', 0, 16),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to prepare highlight for save', [
                        'event_id' => $nostrEvent->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $skipped++;
                }
            }

            // Final flush for remaining events
            $this->entityManager->flush();

            $this->logger->info('Successfully fetched and saved highlights', [
                'saved' => $saved,
                'skipped' => $skipped,
                'total' => count($events)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error fetching highlights from relays', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
