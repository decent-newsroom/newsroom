<?php

namespace App\MessageHandler;

use App\Message\FetchCommentsMessage;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\CommentEventProjector;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchCommentsHandler
{
    private const CACHE_TTL = 30;  // Cache for 30s as performance layer over DB

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly RedisCacheService $redisCacheService,
        private readonly EventRepository $eventRepository,
        private readonly CommentEventProjector $commentProjector,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(FetchCommentsMessage $message): void
    {
        $coordinate = $message->getCoordinate();

        // Step 1: Check cache first (fast path)
        $cached = $this->redisCacheService->getCommentsPayload($coordinate);
        if ($cached) {
            $this->publish($coordinate, $cached['comments'], $cached['profiles']);
            $this->logger->debug('Published cached comments', ['coordinate' => $coordinate]);
            return;
        }

        // Step 2: Get comments from database (stale-while-revalidate)
        $dbComments = $this->eventRepository->findCommentsByCoordinate($coordinate);

        if (!empty($dbComments)) {
            // We have DB comments - publish them immediately
            $comments = $this->convertEventsToObjects($dbComments);
            [$profiles, $maxTs] = $this->hydrateProfilesAndTs($comments);

            $payload = [
                'comments'  => $comments,
                'profiles'  => $profiles,
                'max_ts'    => $maxTs,
                'stored_at' => time(),
            ];

            // Cache for quick subsequent access
            $this->redisCacheService->setCommentsPayload($coordinate, $payload, self::CACHE_TTL);
            $this->publish($coordinate, $comments, $profiles);

            $this->logger->info('Published comments from database', [
                'coordinate' => $coordinate,
                'count' => count($comments)
            ]);
        }

        // Step 3: Refresh from relays (async/background refresh)
        $this->refreshFromRelays($coordinate, $dbComments);
    }

    /**
     * Refresh comments from Nostr relays, persisting new ones to database
     */
    private function refreshFromRelays(string $coordinate, array $existingDbComments): void
    {
        try {
            // Get the latest timestamp from DB for incremental fetch
            $since = $this->eventRepository->findLatestCommentTimestamp($coordinate);

            $this->logger->info('Fetching fresh comments from relays', [
                'coordinate' => $coordinate,
                'since' => $since,
                'existing_count' => count($existingDbComments)
            ]);

            // Fetch from relays (local relay first, then others)
            $newEvents = $this->nostrClient->getComments($coordinate, $since);

            if (empty($newEvents)) {
                $this->logger->debug('No new comments from relays', ['coordinate' => $coordinate]);
                return;
            }

            // Persist new events to database
            $persistedCount = $this->commentProjector->projectEvents($newEvents);

            if ($persistedCount > 0) {
                // Fetch updated list from DB and publish
                $allComments = $this->eventRepository->findCommentsByCoordinate($coordinate);
                $comments = $this->convertEventsToObjects($allComments);
                [$profiles, $maxTs] = $this->hydrateProfilesAndTs($comments);

                $payload = [
                    'comments'  => $comments,
                    'profiles'  => $profiles,
                    'max_ts'    => $maxTs,
                    'stored_at' => time(),
                ];

                // Update cache
                $this->redisCacheService->setCommentsPayload($coordinate, $payload, self::CACHE_TTL);

                // Publish updated comments
                $this->publish($coordinate, $comments, $profiles);

                $this->logger->info('Refreshed and published new comments', [
                    'coordinate' => $coordinate,
                    'new_count' => $persistedCount,
                    'total_count' => count($comments)
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to refresh comments from relays', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Convert Event entities to stdClass objects for compatibility with frontend
     */
    private function convertEventsToObjects(array $events): array
    {
        return array_map(function($event) {
            $obj = new \stdClass();
            $obj->id = $event->getId();
            $obj->kind = $event->getKind();
            $obj->pubkey = $event->getPubkey();
            $obj->content = $event->getContent();
            $obj->created_at = $event->getCreatedAt();
            $obj->tags = $event->getTags();
            $obj->sig = $event->getSig();
            return $obj;
        }, $events);
    }

    /** Collect pubkeys (authors + zappers), hydrate profiles via your Redis cache, compute max_ts */
    private function hydrateProfilesAndTs(array $comments): array
    {
        $keys = [];
        $maxTs = 0;

        foreach ($comments as $c) {
            $maxTs = max($maxTs, (int)($c->created_at ?? 0));
            if (!empty($c->pubkey)) {
                $keys[] = $c->pubkey;
            }
            if (($c->kind ?? null) == 9735) {
                foreach (($c->tags ?? []) as $tag) {
                    if (($tag[0] ?? null) === 'p' && isset($tag[1])) {
                        $keys[] = $tag[1];
                    }
                }
            }
        }

        $keys = array_values(array_unique($keys));
        $profiles = $this->redisCacheService->getMultipleMetadata($keys);

        return [$profiles, $maxTs];
    }

    private function publish(string $coordinate, array $comments, array $profiles): void
    {
        $data = [
            'coordinate' => $coordinate,
            'comments'   => $comments,
            'profiles'   => $profiles,
        ];

        try {
            $topic  = "/comments/" . $coordinate;
            $update = new Update($topic, json_encode($data), false);
            $this->logger->info(sprintf(
                'Publishing comments update for %s (%d comments, %d profiles)',
                $coordinate, count($comments), count($profiles)
            ));
            $this->hub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->error('Error publishing comments update: ' . $e->getMessage());
        }
    }
}
