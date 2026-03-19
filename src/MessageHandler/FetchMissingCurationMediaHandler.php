<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Message\FetchMissingCurationMediaMessage;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchMissingCurationMediaHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly EventIngestionListener $eventIngestionListener,
        private readonly HubInterface $hub,
        private readonly UserRelayListService $userRelayListService,
        private readonly RelayRegistry $relayRegistry,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(FetchMissingCurationMediaMessage $message): void
    {
        $eventIds = array_values(array_unique(array_filter($message->eventIds)));
        if ($eventIds === []) {
            return;
        }

        $existingIds = array_map(
            static fn (Event $event) => $event->getId(),
            $this->eventRepository->findBy(['id' => $eventIds])
        );
        $missingIds = array_values(array_diff($eventIds, $existingIds));

        if ($missingIds === []) {
            return;
        }

        // Build relay list: author's declared relays + hint relays + local relay
        $relays = $this->buildRelayList($message);

        $this->logger->info('Fetching missing curation media events', [
            'curation_id' => $message->curationId,
            'missing_count' => count($missingIds),
            'missing_ids' => array_map(fn(string $id) => substr($id, 0, 12) . '…', $missingIds),
            'relay_count' => count($relays),
            'relays' => $relays,
            'author_pubkey' => $message->authorPubkey ? substr($message->authorPubkey, 0, 12) . '…' : null,
            'hint_relays' => $message->relays,
        ]);

        // Store fetch metadata in Redis so the sync-status endpoint can report it
        $this->recordFetchAttempt($message->curationId, $missingIds, $relays);

        try {
            $fetchedEvents = $this->nostrClient->getEventsByIds($missingIds, $relays);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch missing curation media events', [
                'curation_id' => $message->curationId,
                'relays_tried' => $relays,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $this->logger->info('Curation media fetch completed', [
            'curation_id' => $message->curationId,
            'fetched_count' => count($fetchedEvents),
            'fetched_ids' => array_map(fn(string $id) => substr($id, 0, 12) . '…', array_keys($fetchedEvents)),
            'still_missing' => count($missingIds) - count($fetchedEvents),
        ]);

        if ($fetchedEvents === []) {
            return;
        }

        $persisted = [];
        foreach ($fetchedEvents as $rawEvent) {
            $eventId = $rawEvent->id ?? null;
            if (!is_string($eventId) || $eventId === '' || $this->eventRepository->find($eventId) !== null) {
                continue;
            }

            $entity = new Event();
            $entity->setId($eventId);
            $entity->setKind((int) ($rawEvent->kind ?? 0));
            $entity->setPubkey((string) ($rawEvent->pubkey ?? ''));
            $entity->setContent((string) ($rawEvent->content ?? ''));
            $entity->setCreatedAt((int) ($rawEvent->created_at ?? time()));
            $entity->setTags($this->normalizeTags($rawEvent->tags ?? []));
            $entity->setSig((string) ($rawEvent->sig ?? ''));
            $entity->extractAndSetDTag();

            $this->entityManager->persist($entity);
            $persisted[] = $entity;
        }

        if ($persisted === []) {
            return;
        }

        $this->entityManager->flush();

        foreach ($persisted as $entity) {
            try {
                $this->eventIngestionListener->processEvent($entity);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to update graph tables for curation media event', [
                    'event_id' => $entity->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->publish($message->curationId, $persisted);
    }

    /**
     * Build a comprehensive relay list for fetching missing media:
     *   1. Board author's write relays (kind 10002) — most likely source
     *   2. Relay hints from e-tags in the curation event
     *   3. Local relay as baseline
     */
    private function buildRelayList(FetchMissingCurationMediaMessage $message): array
    {
        $relays = [];

        // 1. Author's declared relays (the board creator's write relays)
        if ($message->authorPubkey !== '') {
            try {
                $authorRelayList = $this->userRelayListService->getRelayList($message->authorPubkey);
                // Prefer write relays (author likely published media to these)
                $authorRelays = !empty($authorRelayList['write'])
                    ? $authorRelayList['write']
                    : ($authorRelayList['all'] ?? []);
                $relays = array_merge($relays, $authorRelays);
            } catch (\Throwable $e) {
                $this->logger->debug('Could not resolve author relay list for curation media fetch', [
                    'author_pubkey' => substr($message->authorPubkey, 0, 12) . '…',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. Hint relays from e-tags
        if (!empty($message->relays)) {
            $relays = array_merge($relays, $message->relays);
        }

        // 3. Ensure local relay is included
        $localRelay = $this->relayRegistry->getLocalRelay();
        if ($localRelay !== null && !in_array($localRelay, $relays, true)) {
            array_unshift($relays, $localRelay);
        }

        return array_values(array_unique($relays));
    }

    /**
     * Store fetch attempt metadata in Redis so the sync-status endpoint can
     * report which relays were tried and when.
     */
    private function recordFetchAttempt(string $curationId, array $missingIds, array $relays): void
    {
        try {
            $key = sprintf('curation_sync:%s', $curationId);
            $data = json_encode([
                'attempted_at' => date('c'),
                'timestamp' => time(),
                'missing_ids' => $missingIds,
                'relays_tried' => $relays,
            ]);
            $this->redis->setex($key, 3600, $data); // 1 hour TTL
        } catch (\Throwable) {
            // Best-effort — don't fail the fetch
        }
    }

    /** @param Event[] $persisted */
    private function publish(string $curationId, array $persisted): void
    {
        try {
            $topic = sprintf('/curation/%s/media-sync', $curationId);
            $update = new Update($topic, json_encode([
                'curationId' => $curationId,
                'count' => count($persisted),
                'eventIds' => array_map(static fn (Event $event) => $event->getId(), $persisted),
            ]), false);
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to publish curation media sync update', [
                'curation_id' => $curationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        return array_map(static function ($tag) {
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }

            return is_array($tag) ? array_values($tag) : [];
        }, $tags);
    }
}

