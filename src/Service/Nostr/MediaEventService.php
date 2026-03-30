<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Media event operations (NIP-68 pictures, NIP-71 videos).
 *
 * Extracted from NostrClient. Collapses three near-identical methods
 * (getPictureEventsForPubkey / getVideoShortsForPubkey / getNormalVideosForPubkey)
 * into one parametrised fetchForPubkey().
 *
 * Supported kinds: 20 (image), 21 (video), 22 (short video),
 *                  34235 (addressable video), 34236 (addressable short video).
 */
class MediaEventService
{
    public function __construct(
        private readonly NostrRequestExecutor   $executor,
        private readonly RelaySetFactory        $relaySetFactory,
        private readonly RelayRegistry          $relayRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface        $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Fetch media events for a specific author.
     *
     * Replaces three separate NostrClient methods:
     *   getPictureEventsForPubkey()   → fetchForPubkey($p, [20])
     *   getNormalVideosForPubkey()    → fetchForPubkey($p, [21, 34235])
     *   getVideoShortsForPubkey()     → fetchForPubkey($p, [22, 34236])
     *   getAllMediaEventsForPubkey()  → fetchForPubkey($p, [20,21,22,34235,34236])
     *
     * @param int[] $kinds NIP-68/71 kinds (default: all media kinds)
     */
    public function fetchForPubkey(string $pubkey, array $kinds = [20, 21, 22, 34235, 34236], int $limit = 30): array
    {
        $relaySet = $this->relaySetFactory->forAuthor($pubkey);

        return $this->executor->fetch(
            kinds: $kinds,
            filters: ['authors' => [$pubkey], 'limit' => $limit],
            relaySet: $relaySet,
        );
    }

    /**
     * Fetch the most recent media events (all kinds) from the default relay set,
     * without filtering by author.
     */
    public function fetchRecent(int $limit = 100): array
    {
        return $this->executor->fetch(
            kinds: [20, 21, 22, 34235, 34236],
            filters: ['limit' => $limit],
            relaySet: $this->relaySetFactory->getDefault(),
        );
    }

    /**
     * Fetch media events matching specific hashtags.
     *
     * @param string[] $hashtags
     * @param int[]    $kinds
     */
    public function fetchByHashtags(array $hashtags, array $kinds = [20, 21, 22, 34235, 34236]): array
    {
        return $this->executor->fetch(
            kinds: $kinds,
            filters: ['tag' => ['#t', $hashtags], 'limit' => 500],
            relaySet: $this->relaySetFactory->fromUrls(['wss://theforest.nostr1.com']),
        );
    }

    /**
     * Fetch media events within a time window.
     *
     * Delegates to NostrRequestExecutor::fetchByTimeRange().
     *
     * @param int[] $kinds
     */
    public function fetchByTimeRange(
        array $kinds = [20, 21, 22, 34235, 34236],
        int   $from = 0,
        int   $to = 0,
        int   $limit = 1000,
    ): array {
        $relaySet = $this->relaySetFactory->fromUrls(
            array_slice($this->relayRegistry->getContentRelays(), 0, 2)
        );

        return $this->executor->fetchByTimeRange(
            kinds: $kinds,
            from: $from,
            to: $to,
            limit: $limit,
            defaultDays: 7,
            relaySet: $relaySet,
        );
    }

    /**
     * Persist a raw Nostr media event to the database.
     *
     * Events are immutable in Nostr; existing events are silently skipped.
     */
    public function save(object $rawEvent): void
    {
        $eventRepo     = $this->entityManager->getRepository(\App\Entity\Event::class);
        $existingEvent = $eventRepo->find($rawEvent->id);

        if ($existingEvent) {
            $this->logger->debug('Media event already exists, skipping', ['event_id' => $rawEvent->id]);
            return;
        }

        $event = new \App\Entity\Event();
        $event->setId($rawEvent->id);
        $event->setPubkey($rawEvent->pubkey);
        $event->setCreatedAt($rawEvent->created_at);
        $event->setKind($rawEvent->kind);
        $event->setTags($rawEvent->tags ?? []);
        $event->setContent($rawEvent->content ?? '');
        $event->setSig($rawEvent->sig);

        $this->entityManager->persist($event);

        $this->logger->debug('Persisted media event', [
            'event_id' => $rawEvent->id,
            'kind'     => $rawEvent->kind,
            'pubkey'   => $rawEvent->pubkey,
        ]);
    }
}

