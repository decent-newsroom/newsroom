<?php

namespace App\Service;

use App\Entity\Article;
use App\Factory\ArticleFactory;
use App\Service\Cache\RedisViewStore;
use App\Service\Graph\EventIngestionListener;
use App\Util\CommonMark\Converter;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
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
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
        private readonly Converter $converter,
        private readonly ProfileUpdateDispatcher $profileUpdateDispatcher,
        private readonly UserRolePromoter $userRolePromoter,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly ReplaceableEventCleanupService $cleanupService,
        private readonly RedisViewStore $viewStore,
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
     * @param bool   $markEssayistExclusive When true, the persisted `Article` row
     *               is flagged `essayist_exclusive = true`. Callers should only
     *               pass `true` when they have already verified that the event
     *               belongs to the Essayist surface (for example: received from
     *               the internal Essayist relay or posted Essayist-only from the editor).
     *               Generic ingestion paths (local strfry router, gateway, fetch
     *               handlers) should pass `false` so public-relay content is not
     *               shadow-hidden by mistake.
     * @throws \Exception
     */
    public function projectArticleFromEvent(object $event, string $relayUrl, bool $markEssayistExclusive = false): void
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

            // Caller-asserted Essayist-exclusivity (see method docblock). The
            // factory deliberately does not infer this on its own; only the
            // caller knows whether the source relay context is Essayist-only.
            if ($markEssayistExclusive) {
                $article->setEssayistExclusive(true);
            }

            // ─── NIP-01 ordering guard ──────────────────────────────────────
            // Parameterized-replaceable events are addressed by (pubkey, kind,
            // d-tag). Before persisting, look at every existing revision for
            // this coordinate and apply NIP-01 replaceability semantics:
            //   1. If any existing revision is *newer* (created_at >, or
            //      created_at = with lexicographically lower event id), the
            //      incoming event is stale — drop it silently. Without this
            //      guard a late-arriving older revision (slow relay, backfill
            //      command, RSS importer reaching back in time) is happily
            //      persisted alongside the current revision, accumulating
            //      duplicate rows in Postgres and ghost docs in Elasticsearch.
            //   2. If existing revisions are all *older*, remove them BEFORE
            //      persisting the new one. Doing the remove first means each
            //      stale row goes through Doctrine's `postRemove` lifecycle,
            //      which fires the FOS Elastica listener and evicts the doc
            //      from the ES index. The new revision then triggers
            //      `postPersist` so ES sees only the latest.
            $articleKind = $article->getKind();
            $articleSlug = $article->getSlug();
            $articlePubkey = $article->getPubkey();
            $articleCreatedAt = $article->getCreatedAt();
            $articleEventId = $article->getEventId() ?? '';

            if ($articleKind && $articleSlug && $articlePubkey && $articleCreatedAt) {
                /** @var \App\Repository\ArticleRepository $articleRepo */
                $articleRepo = $em->getRepository(Article::class);
                $existingRevisions = $articleRepo->findAllRevisionsByCoordinate(
                    $articlePubkey,
                    $articleKind,
                    $articleSlug,
                );

                $newerExists = false;
                $olderRevisions = [];

                foreach ($existingRevisions as $existing) {
                    $existingCreatedAt = $existing->getCreatedAt();
                    $existingEventId = $existing->getEventId() ?? '';

                    if ($existingCreatedAt === null) {
                        // Treat unknown timestamp as older — let the new event win.
                        $olderRevisions[] = $existing;
                        continue;
                    }

                    // NIP-01: newer createdAt wins; on equal createdAt the
                    // lexicographically lower event id wins.
                    if ($existingCreatedAt > $articleCreatedAt) {
                        $newerExists = true;
                        break;
                    }
                    if ($existingCreatedAt == $articleCreatedAt
                        && $existingEventId !== ''
                        && strcmp($existingEventId, $articleEventId) < 0
                    ) {
                        $newerExists = true;
                        break;
                    }

                    $olderRevisions[] = $existing;
                }

                if ($newerExists) {
                    $this->logger->debug('Skipping stale article revision (newer revision already in DB)', [
                        'event_id' => $articleEventId,
                        'pubkey' => substr($articlePubkey, 0, 16) . '...',
                        'kind' => $articleKind->value,
                        'slug' => $articleSlug,
                        'relay' => $relayUrl,
                    ]);
                    return;
                }

                if (!empty($olderRevisions)) {
                    foreach ($olderRevisions as $stale) {
                        $em->remove($stale);
                    }
                    $em->flush();

                    $this->logger->info('Removed older article revisions before persisting new one', [
                        'pubkey' => substr($articlePubkey, 0, 16) . '...',
                        'kind' => $articleKind->value,
                        'slug' => $articleSlug,
                        'removed_count' => count($olderRevisions),
                        'new_event_id' => $articleEventId,
                    ]);
                }
            }
            // ────────────────────────────────────────────────────────────────

            // Process markdown content to HTML for performance optimization
            if ($article->getContent()) {
                try {
                    $processedHtml = $this->converter->convertToHTML(
                        $article->getContent(),
                        null,
                        isset($event->kind) ? (int) $event->kind : null,
                        $event->tags ?? null,
                    );
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

            try {
                $em->persist($article);
                $em->flush();
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
                // Race condition: a concurrent worker persisted the same
                // event id (or coordinate) between our pre-persist guard and
                // our flush. Treat as already-ingested and continue silently
                // — the winning row carries the same event so neither side
                // loses information.
                $this->logger->debug('Concurrent ingestion of same article — yielding to other writer', [
                    'event_id' => $articleEventId,
                    'error' => $e->getMessage(),
                ]);
                $this->managerRegistry->resetManager();
                return;
            }

            $this->logger->info('Article successfully saved to database', [
                'event_id' => $article->getEventId(),
                'db_id' => $article->getId()
            ]);

            // Defensive cleanup: any older revisions that slipped past the
            // pre-persist guard (e.g. inserted by a different writer between
            // our findAllRevisionsByCoordinate() call and our flush) are
            // removed here. Uses ORM remove() so the FOS Elastica listener
            // fires postRemove for each stale row.
            $this->cleanupService->removeOlderArticleRevisions(
                $article,
                $em instanceof \Doctrine\ORM\EntityManagerInterface ? $em : $this->managerRegistry->getManager()
            );

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

            // Trigger async profile fetch for the article author (throttled to avoid queue floods)
            try {
                $this->profileUpdateDispatcher->dispatch($article->getPubkey());
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

            // Invalidate the author's profile-tab caches so the newly ingested
            // article is visible immediately to visitors. Without this the
            // article would be visible on Discover / via direct links but not
            // on the author's profile until the 24h cache entry expires.
            try {
                $this->viewStore->invalidateProfileTabs($article->getPubkey());
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to invalidate author profile tabs after article ingestion', [
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

