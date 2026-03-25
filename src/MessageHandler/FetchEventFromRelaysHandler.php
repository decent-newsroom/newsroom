<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
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
        private readonly HubInterface $hub,
        private readonly \Redis $redis,
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
            $this->publishResult($message->lookupKey, 'found', $event->getId());
            return;
        }

        // Fetch from relays (this is the slow part — OK in a worker)
        $rawEvent = null;
        try {
            $rawEvent = match ($message->type) {
                'naddr' => $this->nostrClient->getEventByNaddr([
                    'kind' => $message->kind,
                    'pubkey' => $message->pubkey,
                    'identifier' => $message->identifier,
                    'relays' => $message->relays,
                ]),
                default => $this->nostrClient->getEventById(
                    $message->eventId,
                    $message->relays,
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
            $this->recordResult($message->lookupKey, 'not_found');
            $this->publishResult($message->lookupKey, 'not_found');
            return;
        }

        // Persist
        try {
            $persisted = $this->genericEventProjector->projectEventFromNostrEvent(
                $rawEvent,
                $message->relays[0] ?? 'async-fetch',
            );
            $this->logger->info('Event fetched and persisted', [
                'lookup_key' => $message->lookupKey,
                'event_id' => $persisted->getId(),
            ]);
            $this->recordResult($message->lookupKey, 'found');
            $this->publishResult($message->lookupKey, 'found', $persisted->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to persist fetched event', [
                'lookup_key' => $message->lookupKey,
                'error' => $e->getMessage(),
            ]);
            $this->recordResult($message->lookupKey, 'error');
            $this->publishResult($message->lookupKey, 'error');
        }
    }

    /**
     * Store result in Redis so the polling status endpoint can respond.
     */
    private function recordResult(string $lookupKey, string $status): void
    {
        try {
            $key = sprintf('event_fetch:%s', $lookupKey);
            $this->redis->setex($key, 300, json_encode([
                'status' => $status,
                'timestamp' => time(),
            ]));
        } catch (\Throwable) {
            // Best-effort — don't break the handler
        }
    }

    /**
     * Publish a Mercure update so the browser reacts instantly.
     */
    private function publishResult(string $lookupKey, string $status, ?string $eventId = null): void
    {
        try {
            $topic = sprintf('/event-fetch/%s', $lookupKey);
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

