<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Event;
use App\Message\FetchMissingCurationMediaMessage;
use App\Repository\EventRepository;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrClient;
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

        try {
            $fetchedEvents = $this->nostrClient->getEventsByIds($missingIds, $message->relays);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch missing curation media events', [
                'curation_id' => $message->curationId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

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

