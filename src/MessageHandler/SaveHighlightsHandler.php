<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\SaveHighlightsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SaveHighlightsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(SaveHighlightsMessage $message): void
    {
        $events = $message->getEvents();

        $this->logger->info('Processing highlights for async save', [
            'count' => count($events)
        ]);

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

        try {
            // Flush all changes at once
            $this->entityManager->flush();

            $this->logger->info('Successfully saved highlights to database', [
                'saved' => $saved,
                'skipped' => $skipped,
                'total' => count($events)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to flush highlight Event entities', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
