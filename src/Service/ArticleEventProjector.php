<?php

namespace App\Service;

use App\Entity\Article;
use App\Factory\ArticleFactory;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

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
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly Converter $converter,
    ) {
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
            // Create Article entity from the event using the factory
            $article = $this->articleFactory->createFromLongFormContentEvent($event);

            // Process markdown content to HTML for performance optimization
            if ($article->getContent()) {
                try {
                    $processedHtml = $this->converter->convertToHTML($article->getContent());
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

            // Check if article with same eventId already exists in the database
            $existingArticle = $this->entityManager
                ->getRepository(Article::class)
                ->findOneBy(['eventId' => $article->getEventId()]);

            if (!$existingArticle) {
                // New article - persist it
                $this->logger->info('Persisting new article from relay', [
                    'event_id' => $article->getEventId(),
                    'kind' => $article->getKind()?->value,
                    'pubkey' => $article->getPubkey(),
                    'title' => $article->getTitle(),
                    'has_processed_html' => $article->getProcessedHtml() !== null,
                    'relay' => $relayUrl
                ]);

                $this->entityManager->persist($article);
                $this->entityManager->flush();

                $this->logger->info('Article successfully saved to database', [
                    'event_id' => $article->getEventId(),
                    'db_id' => $article->getId()
                ]);

                // Note: Post-processing (QA, indexing) will be handled by cron job
                // See: docker/cron/post_process_articles.sh (runs every 5 minutes)

            } else {
                $this->logger->debug('Article already exists in database, skipping', [
                    'event_id' => $article->getEventId(),
                    'db_id' => $existingArticle->getId()
                ]);
            }
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

