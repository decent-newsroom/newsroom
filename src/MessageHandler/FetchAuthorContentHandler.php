<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Enum\AuthorContentType;
use App\Factory\ArticleFactory;
use App\Message\FetchAuthorContentMessage;
use App\Repository\EventRepository;
use App\Service\AuthorRelayService;
use App\Service\Nostr\NostrRelayPool;
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
        private readonly AuthorRelayService $authorRelayService,
        private readonly NostrRelayPool $relayPool,
        private readonly EventRepository $eventRepository,
        private readonly ArticleFactory $articleFactory,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger
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

        $this->logger->info('🚀 FetchAuthorContentHandler invoked - Starting content fetch', [
            'pubkey' => $pubkey,
            'content_types' => array_map(fn($t) => $t->value, $contentTypes),
            'kinds' => $message->getKinds(),
            'since' => $since,
            'is_owner' => $message->isOwner(),
        ]);

        // Get relays - either from message or auto-discover via NIP-65
        $relays = $message->getRelays() ?? $this->authorRelayService->getRelaysForFetching($pubkey);

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

        // Fetch and process each content type
        foreach ($contentTypes as $contentType) {
            try {
                $this->logger->info('📡 Fetching content type ' . $contentType->value . ' from relays', [
                    'content_type' => $contentType->value,
                    'kinds' => $contentType->getKinds(),
                ]);

                $events = $this->fetchContentType($pubkey, $contentType, $relays, $since);
                $processedData = $this->processEvents($pubkey, $contentType, $events);

                // Publish to Mercure for real-time updates
                if (!empty($processedData)) {
                    $this->publishToMercure($pubkey, $contentType, $processedData);
                }

                $this->logger->info('✅ Processed author content type  ' . $contentType->value, [
                    'pubkey' => $pubkey,
                    'content_type' => $contentType->value,
                    'event_count' => count($events),
                    'processed_count' => count($processedData),
                ]);

            } catch (\Exception $e) {
                $this->logger->error('❌ Error fetching content type  ' . $contentType->value, [
                    'pubkey' => $pubkey,
                    'content_type' => $contentType->value,
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Fetch events for a specific content type from relays
     */
    private function fetchContentType(
        string $pubkey,
        AuthorContentType $contentType,
        array $relays,
        int $since
    ): array {
        if (empty($relays)) {
            $this->logger->warning('⚠️  No relays provided to fetchContentType', [
                'pubkey' => $pubkey,
                'content_type' => $contentType->value,
            ]);
            return [];
        }

        $kinds = $contentType->getKinds();

        $this->logger->info('🔍 Preparing to query relays', [
            'pubkey' => $pubkey,
            'content_type' => $contentType->value,
            'kinds' => $kinds,
            'relays' => $relays,
            'relay_count' => count($relays),
            'since' => $since,
        ]);

        try {
            $this->logger->info('📤 Sending filter to relays via NostrRelayPool', [
                'filter' => [
                    'kinds' => $kinds,
                    'authors' => [$pubkey],
                    'limit' => 100,
                    'since' => $since > 0 ? $since : null,
                ]
            ]);

            $responses = $this->relayPool->sendToRelays(
                $relays,
                function () use ($pubkey, $kinds, $since) {
                    $subscription = new Subscription();
                    $subscriptionId = $subscription->setId();

                    $filter = new Filter();
                    $filter->setKinds($kinds);
                    $filter->setAuthors([$pubkey]);
                    $filter->setLimit(100);

                    if ($since > 0) {
                        $filter->setSince($since);
                    }

                    $this->logger->info('🎯 Filter created', [
                        'subscription_id' => $subscriptionId,
                        'filter_details' => [
                            'kinds' => $kinds,
                            'authors' => [$pubkey],
                            'limit' => 100,
                            'since' => $since > 0 ? $since : null,
                        ]
                    ]);

                    return new RequestMessage($subscriptionId, [$filter]);
                },
                10 // 10s timeout per content type — avoid blocking the consumer
            );

            $this->logger->info('📥 Received responses from relays', [
                'response_count' => count($responses),
                'relay_urls' => array_keys($responses),
            ]);

        } catch (\Exception $e) {
            $this->logger->error('❌ Failed to send request to relays', [
                'pubkey' => $pubkey,
                'content_type' => $contentType->value,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }

        if (empty($responses)) {
            $this->logger->warning('⚠️  No responses received from relays', [
                'pubkey' => $pubkey,
                'content_type' => $contentType->value,
                'relay_count' => count($relays),
                'relays' => $relays,
            ]);
            return [];
        }

        // Extract events from responses
        $events = [];
        $seenIds = [];

        foreach ($responses as $relayUrl => $relayResponses) {
            if (!is_array($relayResponses)) {
                $this->logger->warning('⚠️  Invalid relay responses format', [
                    'pubkey' => $pubkey,
                    'content_type' => $contentType->value,
                    'relay_url' => $relayUrl,
                    'type' => gettype($relayResponses),
                ]);
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
                'total_responses' => count($relayResponses),
            ]);
        }

        // Sort by created_at descending
        usort($events, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));

        $this->logger->info('✅ Relay fetch complete', [
            'total_unique_events' => count($events),
            'event_ids' => array_slice(array_keys($seenIds), 0, 5), // Show first 5 IDs
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
        $entity->setEventId($event->id);
        $entity->setKind($event->kind ?? 0);
        $entity->setPubkey($event->pubkey ?? '');
        $entity->setContent($event->content ?? '');
        $entity->setCreatedAt($event->created_at ?? 0);
        $entity->setTags($event->tags ?? []);
        $entity->setSig($event->sig ?? '');

        $this->eventRepository->getEntityManager()->persist($entity);
        $this->eventRepository->getEntityManager()->flush();
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
