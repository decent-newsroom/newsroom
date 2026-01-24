<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Highlight;
use App\Message\FetchHighlightsMessage;
use App\Service\Nostr\NostrClient;
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

                    // Check if highlight already exists by event ID
                    $existingHighlight = $this->entityManager->getRepository(Highlight::class)
                        ->findOneBy(['eventId' => $nostrEvent->id]);
                    if ($existingHighlight) {
                        $this->logger->debug('Highlight already exists, skipping', ['event_id' => $nostrEvent->id]);
                        $skipped++;
                        continue;
                    }

                    // Extract article coordinate from tags (looking for 'a' or 'A' tag with 30023: prefix)
                    $articleCoordinate = null;
                    $context = null;
                    foreach ($nostrEvent->tags ?? [] as $tag) {
                        if (is_array($tag) && count($tag) >= 2) {
                            if (in_array($tag[0], ['a', 'A'])) {
                                // Check for article reference (kind 30023)
                                if (str_starts_with($tag[1] ?? '', '30023:')) {
                                    $articleCoordinate = $tag[1];
                                }
                            }
                            // Extract context if available (quoted text)
                            if ($tag[0] === 'context' && isset($tag[1])) {
                                $context = $tag[1];
                            }
                        }
                    }

                    // Create new Highlight entity (articleCoordinate is optional)
                    $highlight = new Highlight();
                    $highlight->setEventId($nostrEvent->id);
                    $highlight->setArticleCoordinate($articleCoordinate);
                    $highlight->setContent($nostrEvent->content ?? '');
                    $highlight->setPubkey($nostrEvent->pubkey ?? '');
                    $highlight->setCreatedAt($nostrEvent->created_at ?? time());
                    $highlight->setContext($context);
                    $highlight->setRawEvent([
                        'id' => $nostrEvent->id,
                        'kind' => $nostrEvent->kind,
                        'pubkey' => $nostrEvent->pubkey,
                        'content' => $nostrEvent->content,
                        'created_at' => $nostrEvent->created_at,
                        'tags' => $nostrEvent->tags,
                        'sig' => $nostrEvent->sig ?? '',
                    ]);

                    $this->entityManager->persist($highlight);
                    $saved++;

                    // Flush in batches of 20 to improve performance
                    if ($saved % 20 === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }

                    $this->logger->debug('Prepared highlight for save', [
                        'event_id' => $nostrEvent->id,
                        'article_coordinate' => $articleCoordinate,
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
