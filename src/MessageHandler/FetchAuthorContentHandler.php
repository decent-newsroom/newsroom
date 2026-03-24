<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\AuthorContentType;
use App\Factory\ArticleFactory;
use App\Message\FetchAuthorContentMessage;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\UserRelayListService;
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
    public function __construct(
        private readonly UserRelayListService $userRelayListService,
        private readonly NostrRelayPool $relayPool,
        private readonly EventRepository $eventRepository,
        private readonly ArticleFactory $articleFactory,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly EventIngestionListener $eventIngestionListener,
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

        $this->logger->info('🚀 FetchAuthorContentHandler invoked - Starting combined content fetch', [
            'pubkey' => $pubkey,
            'content_types' => array_map(fn($t) => $t->value, $contentTypes),
            'kinds' => $allKinds,
            'since' => $since,
            'is_owner' => $message->isOwner(),
        ]);

        // Get relays - either from message or auto-discover via NIP-65
        $relays = $message->getRelays() ?? $this->userRelayListService->getRelaysForFetching($pubkey);

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
                'limit' => 500,
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
                    $filter->setLimit(500);

                    if ($since > 0) {
                        $filter->setSince($since);
                    }

                    $this->logger->info('🎯 Combined filter created', [
                        'subscription_id' => $subscriptionId,
                        'filter_details' => [
                            'kinds' => $allKinds,
                            'authors' => [$pubkey],
                            'limit' => 500,
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
                        $article = $this->articleFactory->createFromLongFormContentEvent($event);
                        if ($article) {
                            $this->saveArticle($article);

                            // Update graph layer so current_record stays in sync.
                            try {
                                $this->eventIngestionListener->processRawEvent($event);
                            } catch (\Throwable) {}

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

    private function saveArticle($article): void
    {
        $existing = $this->eventRepository->findOneBy(['eventId' => $article->getEventId()]);
        if (!$existing) {
            $this->eventRepository->getEntityManager()->persist($article);
            $this->eventRepository->getEntityManager()->flush();
        }
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

    private function publishToMercure(string $pubkey, AuthorContentType $contentType, array $data): void
    {
        try {
            $topic = $contentType->getMercureTopic($pubkey);
            $update = new Update(
                $topic,
                json_encode([
                    'contentType' => $contentType->value,
                    'items' => $data,
                    'count' => count($data),
                ]),
                false
            );

            $this->hub->publish($update);

            $this->logger->debug('Published to Mercure', [
                'topic' => $topic,
                'item_count' => count($data),
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to publish to Mercure', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
