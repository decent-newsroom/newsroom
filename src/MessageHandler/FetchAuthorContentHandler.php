<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\AuthorContentType;
use App\Factory\ArticleFactory;
use App\Message\FetchAuthorContentMessage;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\Cache\RedisViewStore;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\UserRelayListService;
use App\Util\CommonMark\Converter;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchAuthorContentHandler
{
    /**
     * Per-kind result limit for the combined REQ.
     *
     * The author profile UI displays at most ~20 items per content type. The
     * old value of 500 was wildly excessive and forced relays to do work
     * proportional to it for every kind in the union — directly responsible
     * for the high timeout rate on
     * `kinds=[20,21,22,9802,10015,30023,34235,34236];authors=N1;limit=500`
     * documented in `documentation/Nostr/relay-filter-stats.md`.
     */
    private const FETCH_LIMIT = 100;

    /**
     * Mercure tab badges should represent fresh activity, not historical
     * backfills, so include recent-count metadata bounded to this window.
     */
    private const MERCURE_RECENT_WINDOW_SECONDS = 21600; // 6 hours

    public function __construct(
        private readonly UserRelayListService $userRelayListService,
        private readonly NostrRelayPool $relayPool,
        private readonly EventRepository $eventRepository,
        private readonly ArticleFactory $articleFactory,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly Converter $converter,
        private readonly RedisViewStore $viewStore,
        private readonly ArticleEventProjector $articleEventProjector,
    ) {}

    public function __invoke(FetchAuthorContentMessage $message): void
    {
        $pubkey = $message->getPubkey();
        $contentTypes = $message->getEffectiveContentTypes();
        $since = $message->getSince();

        if (empty($contentTypes)) {
            $this->logger->info('⚠️  No content types to fetch (ownership check filtered all)', [
                'pubkey' => $pubkey
            ]);
            return;
        }

        // Collect all kinds from all content types for a single combined REQ
        $allKinds = AuthorContentType::kindsForTypes($contentTypes);

        // Strip replaceable singleton kinds we already have a recent copy of
        // locally. INTERESTS (10015) is a kind:0-style replaceable: at most one
        // event per pubkey, latest wins. If our DB already has a copy fresher
        // than $since (or fresher than 6 hours when no since), there is no
        // value in asking the relays for it again — and including it just
        // forces every relay in the fan-out to do an extra index lookup.
        $localCutoff = $since > 0 ? $since : (time() - 6 * 3600);
        $allKinds = $this->stripLocallyFreshKinds(
            $pubkey,
            $allKinds,
            [10015 /* INTERESTS */],
            $localCutoff,
        );

        if (empty($allKinds)) {
            $this->logger->info('ℹ️  All requested kinds already locally fresh — skipping relay fetch', [
                'pubkey' => $pubkey,
            ]);
            return;
        }

        $this->logger->info('🚀 FetchAuthorContentHandler invoked - Starting combined content fetch', [
            'pubkey' => $pubkey,
            'content_types' => array_map(fn($t) => $t->value, $contentTypes),
            'kinds' => $allKinds,
            'since' => $since,
            'is_owner' => $message->isOwner(),
        ]);

        // Use the *author's write relays* — i.e. the outbox where they actually
        // publish. The previous default (`getRelaysForFetching`) returned the
        // viewer's follows-pool union of every author the viewer follows, which
        // exploded the fan-out to 500+ relays per REQ and was the dominant
        // source of timeouts on this signature
        // (kinds=[20,21,22,9802,10015,30023,34235,34236];authors=N1;limit=…).
        $relays = $message->getRelays() ?? $this->userRelayListService->getRelaysForAuthorContent($pubkey);

        if (empty($relays)) {
            $this->logger->error('❌ No relays available for fetching author content', [
                'pubkey' => $pubkey,
            ]);
            return;
        }

        $this->logger->info('🔗 Using relays for content fetch (NIP-65 discovery)', [
            'pubkey' => $pubkey,
            'relays' => $relays,
            'relay_count' => count($relays),
        ]);

        // Single combined fetch for all kinds
        try {
            $events = $this->fetchAllContent($pubkey, $allKinds, $relays, $since);

            if (empty($events)) {
                $this->logger->info('⚠️  No events returned from combined fetch', [
                    'pubkey' => $pubkey,
                ]);
                return;
            }

            // Group events by content type via reverse-lookup and process each group
            $eventsByType = [];
            foreach ($events as $event) {
                $kind = (int) ($event->kind ?? 0);
                $type = AuthorContentType::fromKind($kind);
                if ($type !== null && in_array($type, $contentTypes, true)) {
                    $eventsByType[$type->value][] = $event;
                }
            }

            foreach ($contentTypes as $contentType) {
                $typeEvents = $eventsByType[$contentType->value] ?? [];
                if (empty($typeEvents)) {
                    continue;
                }

                try {
                    $processedData = $this->processEvents($pubkey, $contentType, $typeEvents);

                    // Publish to Mercure for real-time updates
                    if (!empty($processedData)) {
                        $this->publishToMercure($pubkey, $contentType, $processedData);
                    }

                    // Invalidate profile tab cache so the next page request
                    // rebuilds from the DB that now contains the relay-fetched
                    // content. Without this the RevalidateProfileCacheHandler
                    // may have already stored a pre-fetch empty (or stale)
                    // payload with a 24h TTL, hiding the freshly-saved articles.
                    if (in_array($contentType, [
                        AuthorContentType::ARTICLES,
                        AuthorContentType::DRAFTS,
                        AuthorContentType::MEDIA,
                        AuthorContentType::HIGHLIGHTS,
                    ], true)) {
                        try {
                            $this->viewStore->invalidateProfileTabs($pubkey);
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to invalidate profile tabs after content fetch', [
                                'pubkey' => $pubkey,
                                'content_type' => $contentType->value,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $this->logger->info('✅ Processed author content type  ' . $contentType->value, [
                        'pubkey' => $pubkey,
                        'content_type' => $contentType->value,
                        'event_count' => count($typeEvents),
                        'processed_count' => count($processedData),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('❌ Error processing content type  ' . $contentType->value, [
                        'pubkey' => $pubkey,
                        'content_type' => $contentType->value,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            $this->logger->info('🏁 Combined content fetch complete', [
                'pubkey' => $pubkey,
                'total_events' => count($events),
                'types_with_events' => array_keys($eventsByType),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('❌ Combined content fetch failed', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Fetch all content kinds in a single REQ to relays.
     * Replaces the previous per-content-type loop.
     */
    private function fetchAllContent(
        string $pubkey,
        array $allKinds,
        array $relays,
        int $since
    ): array {
        if (empty($relays)) {
            return [];
        }

        $this->logger->info('📤 Sending combined filter to relays via NostrRelayPool', [
            'filter' => [
                'kinds' => $allKinds,
                'authors' => [$pubkey],
                'limit' => self::FETCH_LIMIT,
                'since' => $since > 0 ? $since : null,
            ]
        ]);

        try {
            $responses = $this->relayPool->sendToRelays(
                $relays,
                function () use ($pubkey, $allKinds, $since) {
                    $subscription = new Subscription();
                    $subscriptionId = $subscription->setId();

                    $filter = new Filter();
                    $filter->setKinds($allKinds);
                    $filter->setAuthors([$pubkey]);
                    $filter->setLimit(self::FETCH_LIMIT);

                    if ($since > 0) {
                        $filter->setSince($since);
                    }

                    $this->logger->info('🎯 Combined filter created', [
                        'subscription_id' => $subscriptionId,
                        'filter_details' => [
                            'kinds' => $allKinds,
                            'authors' => [$pubkey],
                            'limit' => self::FETCH_LIMIT,
                            'since' => $since > 0 ? $since : null,
                        ]
                    ]);

                    return new RequestMessage($subscriptionId, [$filter]);
                },
                15 // Slightly higher timeout for combined fetch
            );
        } catch (\Exception $e) {
            $this->logger->error('❌ Failed to send combined request to relays', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        if (empty($responses)) {
            return [];
        }

        // Extract and deduplicate events from all relays
        $events = [];
        $seenIds = [];

        foreach ($responses as $relayUrl => $relayResponses) {
            if (!is_array($relayResponses)) {
                continue;
            }

            $relayEventCount = 0;
            foreach ($relayResponses as $response) {
                if (isset($response->type) && $response->type === 'EVENT' && isset($response->event)) {
                    $eventId = $response->event->id ?? null;
                    if ($eventId && !isset($seenIds[$eventId])) {
                        $seenIds[$eventId] = true;
                        $events[] = $response->event;
                        $relayEventCount++;
                    }
                }
            }

            $this->logger->info('📊 Relay response processed', [
                'relay_url' => $relayUrl,
                'events_from_relay' => $relayEventCount,
            ]);
        }

        // Sort by created_at descending
        usort($events, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));

        $this->logger->info('✅ Combined relay fetch complete', [
            'total_unique_events' => count($events),
        ]);

        return $events;
    }

    /**
     * Process and save events based on content type
     */
    private function processEvents(string $pubkey, AuthorContentType $contentType, array $events): array
    {
        $processedData = [];

        foreach ($events as $event) {
            try {
                switch ($contentType) {
                    case AuthorContentType::ARTICLES:
                    case AuthorContentType::DRAFTS:
                        // Route through ArticleEventProjector so the same
                        // NIP-01 ordering guard, ES-aware revision cleanup,
                        // and graph-table sync that the strfry subscription
                        // worker uses also run on author-refresh fetches.
                        // The projector is idempotent on event-id duplicates
                        // and silently drops stale revisions, so passing
                        // every event through it is safe.
                        try {
                            $this->articleEventProjector->projectArticleFromEvent($event, 'author-refresh');
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to project article during author refresh', [
                                'event_id' => $event->id ?? 'unknown',
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // For UI streaming we still serialize what we just saw.
                        $article = $this->articleFactory->createFromLongFormContentEvent($event);
                        if ($article) {

                            $processedData[] = $this->serializeArticle($article);
                        }
                        break;

                    case AuthorContentType::MEDIA:
                        $this->saveMediaEvent($event);
                        $processedData[] = $this->serializeMediaEvent($event);
                        break;

                    case AuthorContentType::HIGHLIGHTS:
                        $this->saveEvent($event);
                        $processedData[] = $this->serializeHighlight($event);
                        break;

                    case AuthorContentType::BOOKMARKS:
                    case AuthorContentType::INTERESTS:
                        $this->saveEvent($event);
                        $processedData[] = $this->serializeListEvent($event);
                        break;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to process event', [
                    'event_id' => $event->id ?? 'unknown',
                    'content_type' => $contentType->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processedData;
    }


    private function saveMediaEvent(object $event): void
    {
        $this->saveEvent($event);
    }

    private function saveEvent(object $event): void
    {
        $existing = $this->eventRepository->find($event->id ?? '');
        if ($existing) {
            return;
        }

        $entity = new Event();
        $entity->setId($event->id);
        $entity->setKind($event->kind ?? 0);
        $entity->setPubkey($event->pubkey ?? '');
        $entity->setContent($event->content ?? '');
        $entity->setCreatedAt($event->created_at ?? 0);
        $entity->setTags($event->tags ?? []);
        $entity->setSig($event->sig ?? '');
        $entity->extractAndSetDTag();

        $this->eventRepository->getEntityManager()->persist($entity);
        $this->eventRepository->getEntityManager()->flush();

        // Update graph layer tables
        try {
            $this->eventIngestionListener->processEvent($entity);
        } catch (\Throwable) {}
    }

    private function serializeArticle($article): array
    {
        return [
            'id' => $article->getId(),
            'slug' => $article->getSlug(),
            'title' => $article->getTitle(),
            'summary' => $article->getSummary(),
            'image' => $article->getImage(),
            'createdAt' => $article->getCreatedAt()->getTimestamp(),
            'pubkey' => $article->getPubkey(),
            'topics' => $article->getTopics(),
        ];
    }

    private function serializeMediaEvent(object $event): array
    {
        $url = null;
        $mimeType = null;
        $alt = null;

        foreach ($event->tags ?? [] as $tag) {
            if (($tag[0] ?? '') === 'url') $url = $tag[1] ?? null;
            if (($tag[0] ?? '') === 'm') $mimeType = $tag[1] ?? null;
            if (($tag[0] ?? '') === 'alt') $alt = $tag[1] ?? null;
        }

        return [
            'id' => $event->id,
            'kind' => $event->kind,
            'url' => $url ?? $event->content ?? '',
            'mimeType' => $mimeType,
            'alt' => $alt,
            'createdAt' => $event->created_at,
        ];
    }

    private function serializeHighlight(object $event): array
    {
        $context = null;
        $sourceUrl = null;

        foreach ($event->tags ?? [] as $tag) {
            if (($tag[0] ?? '') === 'context') $context = $tag[1] ?? null;
            if (($tag[0] ?? '') === 'r') $sourceUrl = $tag[1] ?? null;
        }

        return [
            'id' => $event->id,
            'content' => $event->content ?? '',
            'context' => $context,
            'sourceUrl' => $sourceUrl,
            'createdAt' => $event->created_at,
        ];
    }

    private function serializeListEvent(object $event): array
    {
        $items = [];
        $identifier = null;

        foreach ($event->tags ?? [] as $tag) {
            if (($tag[0] ?? '') === 'd') {
                $identifier = $tag[1] ?? null;
            }
            if (in_array($tag[0] ?? '', ['e', 'a', 'p', 't'])) {
                $items[] = [
                    'type' => $tag[0],
                    'value' => $tag[1] ?? null,
                    'relay' => $tag[2] ?? null,
                ];
            }
        }

        return [
            'id' => $event->id,
            'kind' => $event->kind,
            'identifier' => $identifier,
            'items' => $items,
            'createdAt' => $event->created_at,
        ];
    }

    /**
     * Drop kinds from the REQ list that we already have a sufficiently fresh
     * local copy of. Only safe for replaceable singleton kinds (kind:0,
     * kind:3, NIP-51 replaceable lists 10000-19999, etc.) where at most one
     * event per pubkey ever exists and the latest wins.
     *
     * @param int[] $kinds                 All kinds being requested
     * @param int[] $replaceableSingletons Subset of $kinds that are replaceable singletons
     * @param int   $freshnessCutoff       Unix ts; a local copy newer than this is "fresh enough"
     * @return int[] Remaining kinds that still need to be fetched from relays
     */
    private function stripLocallyFreshKinds(
        string $pubkey,
        array $kinds,
        array $replaceableSingletons,
        int $freshnessCutoff,
    ): array {
        $stripped = [];
        foreach ($replaceableSingletons as $kind) {
            if (!in_array($kind, $kinds, true)) {
                continue;
            }
            try {
                $existing = $this->eventRepository->findLatestByPubkeyAndKind($pubkey, $kind);
            } catch (\Throwable) {
                $existing = null;
            }
            if ($existing !== null && $existing->getCreatedAt() >= $freshnessCutoff) {
                $stripped[] = $kind;
            }
        }

        if ($stripped === []) {
            return $kinds;
        }

        $this->logger->debug('Skipping locally-fresh replaceable kinds from REQ', [
            'pubkey' => $pubkey,
            'stripped_kinds' => $stripped,
            'freshness_cutoff' => $freshnessCutoff,
        ]);

        return array_values(array_diff($kinds, $stripped));
    }

    private function publishToMercure(string $pubkey, AuthorContentType $contentType, array $data): void
    {
        try {
            $topic = $contentType->getMercureTopic($pubkey);
            $newestCreatedAt = $this->extractNewestCreatedAt($data);
            $recentCount = $this->countRecentItems($data, time() - self::MERCURE_RECENT_WINDOW_SECONDS);

            $update = new Update(
                $topic,
                json_encode([
                    'contentType' => $contentType->value,
                    'items' => $data,
                    'count' => count($data),
                    'recentCount' => $recentCount,
                    'newestCreatedAt' => $newestCreatedAt,
                    'publishedAt' => time(),
                ]),
                false
            );

            $this->hub->publish($update);

            $this->logger->debug('Published to Mercure', [
                'topic' => $topic,
                'item_count' => count($data),
                'recent_count' => $recentCount,
                'newest_created_at' => $newestCreatedAt,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to publish to Mercure', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractNewestCreatedAt(array $items): ?int
    {
        $newest = null;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $createdAt = $this->extractCreatedAt($item);
            if ($createdAt === null) {
                continue;
            }

            if ($newest === null || $createdAt > $newest) {
                $newest = $createdAt;
            }
        }

        return $newest;
    }

    private function countRecentItems(array $items, int $cutoff): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $createdAt = $this->extractCreatedAt($item);
            if ($createdAt !== null && $createdAt >= $cutoff) {
                $count++;
            }
        }

        return $count;
    }

    private function extractCreatedAt(array $item): ?int
    {
        $value = $item['createdAt'] ?? $item['created_at'] ?? null;
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            return null;
        }

        $timestamp = (int) $value;
        return $timestamp > 0 ? $timestamp : null;
    }
}
