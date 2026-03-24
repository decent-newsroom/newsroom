<?php

namespace App\Service;

use App\Entity\Article;
use App\Factory\ArticleFactory;
use App\Message\UpdateProfileProjectionMessage;
use App\Service\Graph\EventIngestionListener;
use App\Util\CommonMark\Converter;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Projects Nostr article events into the database
 * Handles the conversion from event format to Article entity and persistence
 * Also processes markdown content to HTML for performance optimization
 *
 * Note: Post-processing (QA, indexing) is handled by cron job running articles:post-process
 */
class ArticleEventProjector
{
    public function __construct(
        private readonly ArticleFactory $articleFactory,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly Converter $converter,
        private readonly MessageBusInterface $messageBus,
        private readonly UserRolePromoter $userRolePromoter,
        private readonly EventIngestionListener $eventIngestionListener,
    ) {
    }

    /**
     * Returns a live (non-closed) EntityManager.
     * After a resetManager() call the injected $this->entityManager is stale;
     * always go through the registry so we get the current instance.
     */
    private function em(): ObjectManager
    {
        $em = $this->managerRegistry->getManagerForClass(Article::class);
        if ($em === null || ($em instanceof \Doctrine\ORM\EntityManagerInterface && !$em->isOpen())) {
            $em = $this->managerRegistry->resetManager();
        }
        return $em;
    }

    /**
     * Project a Nostr event into the database
     * Creates or updates an Article entity from the event data
     *
     * @param object $event The Nostr event object (stdClass with id, kind, pubkey, content, tags, etc.)
     * @param string $relayUrl The relay URL where the event was received from
     * @throws \Exception
     */
    public function projectArticleFromEvent(object $event, string $relayUrl): void
    {
        try {
            $em = $this->em();

            // Early exit: check if article already exists before doing expensive work
            $eventId = $event->id ?? null;
            if ($eventId) {
                $existingArticle = $em
                    ->getRepository(Article::class)
                    ->findOneBy(['eventId' => $eventId]);

                if ($existingArticle) {
                    $this->logger->debug('Article already exists in database, skipping', [
                        'event_id' => $eventId,
                        'db_id' => $existingArticle->getId()
                    ]);
                    return;
                }
            }

            // Create Article entity from the event using the factory
            $article = $this->articleFactory->createFromLongFormContentEvent($event);

            // Process markdown content to HTML for performance optimization
            if ($article->getContent()) {
                try {
                    $processedHtml = $this->converter->convertToHTML($article->getContent(), null, $event->tags ?? null);
                    $article->setProcessedHtml($processedHtml);

                    $this->logger->debug('Processed article HTML', [
                        'event_id' => $article->getEventId(),
                        'content_length' => strlen($article->getContent()),
                        'html_length' => strlen($processedHtml)
                    ]);
                } catch (\Exception $e) {
                    // If HTML conversion fails, log but continue (HTML will be null)
                    $this->logger->warning('Failed to process article HTML', [
                        'event_id' => $article->getEventId(),
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // New article - persist it
            $this->logger->info('Persisting new article from relay', [
                'event_id' => $article->getEventId(),
                'kind' => $article->getKind()?->value,
                'pubkey' => $article->getPubkey(),
                'title' => $article->getTitle(),
                'has_processed_html' => $article->getProcessedHtml() !== null,
                'relay' => $relayUrl
            ]);

            $em->persist($article);
            $em->flush();

            $this->logger->info('Article successfully saved to database', [
                'event_id' => $article->getEventId(),
                'db_id' => $article->getId()
            ]);

            // Update graph layer tables (parsed_reference + current_record)
            // so the graph stays in sync without needing backfill/audit repair.
            try {
                $this->eventIngestionListener->processRawEvent($event);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update graph tables for article event', [
                    'event_id' => $article->getEventId(),
                    'error' => $e->getMessage(),
                ]);
            }

            // Trigger async profile fetch for the article author
            try {
                $this->messageBus->dispatch(new UpdateProfileProjectionMessage($article->getPubkey()));
                $this->logger->debug('Dispatched profile fetch for article author', [
                    'pubkey' => substr($article->getPubkey(), 0, 16) . '...',
                    'event_id' => $article->getEventId()
                ]);
            } catch (\Exception $e) {
                // Log error but don't fail the article ingestion
                $this->logger->error('Failed to dispatch profile fetch for article author', [
                    'pubkey' => $article->getPubkey(),
                    'event_id' => $article->getEventId(),
                    'error' => $e->getMessage()
                ]);
            }

            // Grant ROLE_WRITER to the article author (if they have a local account)
            try {
                $this->userRolePromoter->promoteToWriter($article->getPubkey());
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to promote article author to ROLE_WRITER', [
                    'pubkey' => substr($article->getPubkey(), 0, 16) . '...',
                    'error' => $e->getMessage(),
                ]);
            }

            // Note: Post-processing (QA, indexing) will be handled by cron job
            // See: docker/cron/post_process_articles.sh (runs every 5 minutes)

        } catch (\InvalidArgumentException $e) {
            // Invalid event (wrong kind, invalid signature, etc.)
            $this->logger->warning('Invalid article event received', [
                'event_id' => $event->id ?? 'unknown',
                'error' => $e->getMessage(),
                'relay' => $relayUrl
            ]);
            throw $e;
        } catch (\Exception $e) {
            // Database or other errors
            $this->logger->error('Error projecting article event', [
                'event_id' => $event->id ?? 'unknown',
                'error' => $e->getMessage(),
                'relay' => $relayUrl
            ]);

            // Reset entity manager on error to prevent further issues
            $this->managerRegistry->resetManager();
            throw $e;
        }
    }
}

