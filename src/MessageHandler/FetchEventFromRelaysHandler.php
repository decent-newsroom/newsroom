<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\GenericEventProjector;
use App\Service\Nostr\EventLookupKey;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchEventFromRelaysHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EventRepository $eventRepository,
        private readonly GenericEventProjector $genericEventProjector,
        private readonly ArticleEventProjector $articleEventProjector,
        private readonly UserRelayListService $userRelayListService,
        private readonly HubInterface $hub,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(FetchEventFromRelaysMessage $message): void
    {
        $this->logger->info('Async event fetch started', [
            'lookup_key' => $message->lookupKey,
            'type' => $message->type,
        ]);

        // Check if the event has already been fetched (race / another worker)
        $event = match ($message->type) {
            'naddr' => $this->eventRepository->findByNaddr(
                $message->kind,
                $message->pubkey,
                $message->identifier,
            ),
            default => $message->eventId
                ? $this->eventRepository->findById($message->eventId)
                : null,
        };

        if ($event) {
            $this->logger->info('Event already in DB, skipping relay fetch', [
                'lookup_key' => $message->lookupKey,
            ]);
            $this->invalidateChapterCaches($message, $event->getId());
            $this->publishResult($message->lookupKey, 'found', $event->getId());
            return;
        }

        // Fetch from relays (this is the slow part — OK in a worker).
        // Enrich with the author's NIP-65 relays whenever the author is known;
        // notes and naddr events are often only available on personal relays.
        $relays = $message->relays;
        if ($message->pubkey) {
            try {
                $authorRelays = $this->userRelayListService->getRelaysForFetching($message->pubkey);
                foreach ($authorRelays as $ar) {
                    if (!in_array($ar, $relays, true)) {
                        $relays[] = $ar;
                    }
                }
                $this->logger->info('Enriched relay list with author relays', [
                    'lookup_key' => $message->lookupKey,
                    'author_relays' => $authorRelays,
                    'total_relays' => count($relays),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to resolve author relays for async fetch', [
                    'lookup_key' => $message->lookupKey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $rawEvent = null;
        try {
            $rawEvent = match ($message->type) {
                'naddr' => $this->nostrClient->getEventByNaddr([
                    'kind' => $message->kind,
                    'pubkey' => $message->pubkey,
                    'identifier' => $message->identifier,
                    'relays' => $relays,
                ], allowRelayListNetworkFetch: true, gatewayTimeout: 15, directTimeout: 10),
                default => $this->nostrClient->getEventById(
                    $message->eventId,
                    $relays,
                    gatewayTimeout: 15,
                    directTimeout: 10,
                ),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Relay fetch failed', [
                'lookup_key' => $message->lookupKey,
                'error' => $e->getMessage(),
            ]);
        }

        if ($rawEvent === null) {
            $this->logger->info('Event not found on relays', [
                'lookup_key' => $message->lookupKey,
            ]);
            $this->publishResult($message->lookupKey, 'not_found');
            return;
        }

        // Persist
        try {
            $relaySource = $relays[0] ?? 'async-fetch';
            $persisted = $this->genericEventProjector->projectEventFromNostrEvent(
                $rawEvent,
                $relaySource,
            );

            // For article events, also project the Article entity so that
            // article routes (/article/d/{slug}, /p/{npub}/d/{slug}) work
            // after the redirect.  GenericEventProjector only creates Event
            // entities; ArticleEventProjector creates the Article row.
            $rawKind = (int) ($rawEvent->kind ?? 0);
            if (in_array($rawKind, [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value], true)) {
                try {
                    $this->articleEventProjector->projectArticleFromEvent($rawEvent, $relaySource);
                } catch (\Throwable $e) {
                    $this->logger->warning('Article projection failed (Event entity still saved)', [
                        'lookup_key' => $message->lookupKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Event fetched and persisted', [
                'lookup_key' => $message->lookupKey,
                'event_id' => $persisted->getId(),
            ]);
            $this->invalidateChapterCaches($message, $persisted->getId());
            $this->publishResult($message->lookupKey, 'found', $persisted->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist fetched event', [
                'lookup_key' => $message->lookupKey,
                'event_id' => $rawEvent->id ?? null,
                'kind' => $rawEvent->kind ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->publishResult($message->lookupKey, 'error');
        }
    }

    private function invalidateChapterCaches(FetchEventFromRelaysMessage $message, ?string $eventId): void
    {
        if ($message->kind !== KindsEnum::PUBLICATION_CONTENT->value) {
            return;
        }

        try {
            if (is_string($message->mag) && $message->mag !== '') {
                $this->cache->deleteItem('magazine_chapters_frame_' . $message->mag);
            }
            if (is_string($eventId) && $eventId !== '') {
                $this->cache->deleteItem('chapter_' . $eventId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to invalidate chapter caches after async fetch', [
                'lookup_key' => $message->lookupKey,
                'mag' => $message->mag,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Publish a Mercure update so the browser reacts instantly.
     */
    private function publishResult(string $lookupKey, string $status, ?string $eventId = null): void
    {
        try {
            $topic = EventLookupKey::topic($lookupKey);
            $update = new Update($topic, json_encode([
                'status' => $status,
                'eventId' => $eventId,
            ]), false);
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for event fetch', [
                'lookup_key' => $lookupKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
