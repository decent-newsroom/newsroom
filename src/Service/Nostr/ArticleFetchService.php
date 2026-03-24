<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Service\Graph\EventIngestionListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

/**
 * All article / long-form content operations, extracted from NostrClient.
 *
 * Owns:
 *   - Fetching long-form events by pubkey, slug, naddr, or coordinate
 *   - Persisting Article entities to the database
 *   - Ingesting a time-range of long-form events
 */
class ArticleFetchService
{
    public function __construct(
        private readonly NostrRequestExecutor  $executor,
        private readonly RelaySetFactory       $relaySetFactory,
        private readonly RelayRegistry         $relayRegistry,
        private readonly ArticleFactory        $articleFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry       $managerRegistry,
        private readonly LoggerInterface       $logger,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly ?string               $nostrDefaultRelay = null,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fetch long-form (or any kind) articles for a pubkey and save them to the DB.
     *
     * @return Article[]
     */
    public function fetchForPubkey(string $pubkey, ?int $since = null, int $kind = KindsEnum::LONGFORM->value): array
    {
        $relaySet = $this->relaySetFactory->forAuthor($pubkey);

        $this->logger->info('Fetching articles for pubkey', [
            'pubkey'    => $pubkey,
            'kind'      => $kind,
            'relay_set' => $this->nostrDefaultRelay ? 'local' : 'author',
        ]);

        $filters = ['authors' => [$pubkey], 'limit' => 20];
        if ($since !== null && $since > 0) {
            $filters['since'] = $since;
        }

        return $this->executor->fetch(
            kinds: [$kind],
            filters: $filters,
            relaySet: $relaySet,
            handler: function ($event) {
                $article = $this->articleFactory->createFromLongFormContentEvent($event);
                $this->save($article);

                // Update graph layer so current_record stays in sync.
                try {
                    $this->eventIngestionListener->processRawEvent($event);
                } catch (\Throwable) {}

                return $article;
            }
        );
    }

    /**
     * Fetch a single long-form event by naddr components and save to DB.
     *
     * Tries author relays first, falls back to content relays.
     *
     * @throws \Exception on relay error
     */
    public function fetchFromNaddr(string $slug, array $relayList, string $author, int $kind): bool
    {
        $this->logger->info('Fetching long-form from naddr', [
            'slug'       => $slug,
            'author'     => $author,
            'kind'       => $kind,
            'relay_list' => $relayList,
        ]);

        // Build a best-guess primary relay set that combines all available hints:
        //   1. naddr hint relays (embedded in the link by the original publisher)
        //   2. Author's declared write relays (kind 10002)
        //   3. Local relay (fast local cache)
        // This single broad set usually finds the article in one round-trip.
        $authorRelays  = $this->relaySetFactory->getAuthorRelayUrls($author);
        $localRelays   = $this->relayRegistry->getFallbackRelays();
        $primaryUrls   = array_values(array_unique(array_merge($relayList, $authorRelays, $localRelays)));

        $this->logger->debug('naddr primary relay set', [
            'hint_relays'   => $relayList,
            'author_relays' => $authorRelays,
            'local_relays'  => $localRelays,
            'combined'      => $primaryUrls,
        ]);

        $primaryRelaySet  = $this->relaySetFactory->fromUrls($primaryUrls);

        // Fallback: content relays (broad network coverage) merged with any
        // hint URLs not already covered.
        $contentRelays    = $this->relayRegistry->getContentRelays();
        $fallbackUrls     = array_values(array_unique(array_merge($contentRelays, $relayList)));
        $fallbackRelaySet = $this->relaySetFactory->fromUrls($fallbackUrls);

        $filters = [
            'authors' => [$author],
            'tag'     => ['#d', [$slug]],
        ];

        try {
            $event = $this->executor->fetchFirst([$kind], $filters, $primaryRelaySet, $fallbackRelaySet);

            if ($event !== null) {
                $this->saveFromWrapper($event);
                return true;
            }

            $this->logger->warning('No events found even after fallback relay search', [
                'slug'   => $slug,
                'author' => $author,
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error querying relays', ['error' => $e->getMessage()]);
            throw new \Exception('Error querying relays', 0, $e);
        }
    }

    /**
     * Fetch the latest long-form articles from the local/default relay and save to DB.
     *
     * @return Article[]
     */
    public function fetchLatest(int $limit = 50, ?int $since = null): array
    {
        $relaySet = $this->nostrDefaultRelay
            ? $this->relaySetFactory->fromUrls([$this->nostrDefaultRelay])
            : $this->relaySetFactory->getDefault();

        $this->logger->info('Fetching latest long-form articles', [
            'relay'  => $this->nostrDefaultRelay ?? 'default',
            'limit'  => $limit,
            'since'  => $since,
        ]);

        $filters = ['limit' => $limit];
        if ($since !== null && $since > 0) {
            $filters['since'] = $since;
        }

        try {
            $events = $this->executor->fetch(
                kinds: [KindsEnum::LONGFORM->value],
                filters: $filters,
                relaySet: $relaySet,
                handler: function ($event) {
                    try {
                        $article = $this->articleFactory->createFromLongFormContentEvent($event);
                        $this->save($article);

                        // Update graph layer so current_record stays in sync.
                        try {
                            $this->eventIngestionListener->processRawEvent($event);
                        } catch (\Throwable) {}

                        return $article;
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed converting event to Article', [
                            'error'    => $e->getMessage(),
                            'event_id' => $event->id ?? null,
                        ]);
                        return null;
                    }
                }
            );
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching latest long-form articles', ['error' => $e->getMessage()]);
            return [];
        }

        $articles = array_filter($events, fn($a) => $a instanceof Article);

        // Deduplicate by slug, keeping newest
        $bySlug = [];
        foreach ($articles as $article) {
            $slug = $article->getSlug();
            if ($slug === '') {
                continue;
            }
            if (!isset($bySlug[$slug]) || $article->getCreatedAt() > $bySlug[$slug]->getCreatedAt()) {
                $bySlug[$slug] = $article;
            }
        }

        $deduped = array_values($bySlug);
        usort($deduped, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        return $deduped;
    }

    /**
     * Fetch articles by a list of #d tag slugs from the default relay set.
     *
     * @return array<string, object>  event ID => event
     */
    public function fetchBySlugList(array $slugs): array
    {
        if (empty($slugs)) {
            return [];
        }

        $events = $this->executor->fetch(
            kinds: [KindsEnum::LONGFORM],
            filters: ['tag' => ['#d', $slugs]],
            relaySet: $this->relaySetFactory->getDefault(),
        );

        $articles = [];
        foreach ($events as $event) {
            $articles[$event->id] = $event;
        }
        return $articles;
    }

    /**
     * Fetch articles by an array of coordinate strings ("kind:pubkey:identifier").
     *
     * @return array<string, object>  coordinate => event
     */
    public function fetchByCoordinates(array $coordinates): array
    {
        $articlesMap = [];

        foreach ($coordinates as $coordinate) {
            $parts  = explode(':', $coordinate, 3);
            $kind   = (int) $parts[0];
            $pubkey = $parts[1];
            $slug   = $parts[2];

            $relaySet = $this->relaySetFactory->forAuthor($pubkey);

            try {
                $filters = ['authors' => [$pubkey], 'tag' => ['#d', [$slug]]];
                $events  = $this->executor->process(
                    $this->executor->execute($this->executor->buildRequest([$kind], $filters, $relaySet)),
                    function ($event) use ($coordinate) {
                        $this->logger->info('Received event for coordinate', [
                            'event_id'   => $event->id,
                            'coordinate' => $coordinate,
                        ]);
                        return $event;
                    }
                );

                if (!empty($events)) {
                    $articlesMap[$coordinate] = $events[0];
                    $this->logger->info('Found article in author relays', ['coordinate' => $coordinate]);
                } else {
                    $fallbackRequest = $this->executor->buildRequest([$kind], [
                        'authors' => [$pubkey],
                        'tag'     => ['#d', [$slug]],
                    ], $this->relaySetFactory->getDefault());

                    $fallbackEvents = $this->executor->process(
                        $this->executor->execute($fallbackRequest),
                        fn($event) => $event
                    );

                    if (!empty($fallbackEvents)) {
                        $articlesMap[$coordinate] = $fallbackEvents[0];
                        $this->logger->info('Found article in default relays', ['coordinate' => $coordinate]);
                    } else {
                        $this->logger->warning('Article not found in any relay', ['coordinate' => $coordinate]);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Error fetching article', [
                    'coordinate' => $coordinate,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Finished fetching articles by coordinates', [
            'total_coordinates' => count($coordinates),
            'articles_found'    => count($articlesMap),
        ]);

        return $articlesMap;
    }

    /**
     * Ingest long-form events between two timestamps, saving each to the DB.
     * (Was getLongFormContent() in NostrClient.)
     */
    public function ingestRange(?int $from = null, ?int $to = null): void
    {
        $relaySet = $this->relaySetFactory->withLocalRelay($this->relayRegistry->getContentRelays());

        $filters = ['since' => $from ?? strtotime('-1 week')];
        if ($to !== null) {
            $filters['until'] = $to;
        }

        $events = $this->executor->fetch(
            kinds: [KindsEnum::LONGFORM],
            filters: $filters,
            relaySet: $relaySet,
        );

        foreach ($events as $event) {
            $article = $this->articleFactory->createFromLongFormContentEvent($event);
            $this->save($article);

            // Update graph layer (parsed_reference + current_record) so the
            // nightly audit does not need to repair these entries.
            try {
                $this->eventIngestionListener->processRawEvent($event);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update graph tables for ingested article', [
                    'event_id' => $event->id ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Persist an Article to the database if it does not already exist.
     */
    public function save(Article $article): void
    {
        $saved = $this->entityManager->getRepository(Article::class)
            ->findOneBy(['eventId' => $article->getEventId()]);

        if (!$saved) {
            try {
                $this->logger->info('Saving article', ['article' => $article]);
                $this->entityManager->persist($article);
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                $this->managerRegistry->resetManager();
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Wrap a raw event object in a minimal envelope and save it.
     */
    private function saveFromWrapper(object $event): void
    {
        $article = $this->articleFactory->createFromLongFormContentEvent($event);
        $this->save($article);

        // Update graph layer so current_record stays in sync.
        try {
            $this->eventIngestionListener->processRawEvent($event);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to update graph tables for article', [
                'event_id' => $event->id ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }
}

