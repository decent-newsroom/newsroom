<?php

namespace App\MessageHandler;

use App\Message\FetchCommentsMessage;
use App\Service\NostrClient;
use App\Service\NostrLinkParser;
use App\Service\RedisCacheService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[AsMessageHandler]
class FetchCommentsHandler
{
    private const SOFT_TTL = 10;   // serve cached instantly within 10s
    private const HARD_TTL = 60;   // force refresh after 60s
    private const CACHE_TTL = 90;  // stored item TTL (just > HARD_TTL)

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly NostrLinkParser $nostrLinkParser,
        private readonly RedisCacheService $redisCacheService,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(FetchCommentsMessage $message): void
    {
        $coordinate = $message->getCoordinate();

        // 0) Try cache
        $cached = $this->redisCacheService->getCommentsPayload($coordinate);
        $now    = time();
        $age    = $cached ? $now - (int)($cached['stored_at'] ?? 0) : PHP_INT_MAX;

        // 1) Fresh enough? Publish and return
        if ($cached && $age <= self::SOFT_TTL) {
            $this->publish($coordinate, $cached['comments'], $cached['profiles']);
            return;
        }

        // 2) Soft-stale: publish cached immediately, then refresh incrementally
        if ($cached && $age <= self::HARD_TTL) {
            $this->publish($coordinate, $cached['comments'], $cached['profiles']);
            $this->refreshIncremental($coordinate, $cached);
            return;
        }

        // 3) No cache or hard-stale: full refresh
        $this->refreshFull($coordinate, $cached);
    }

    private function refreshIncremental(string $coordinate, array $cached): void
    {
        try {
            $sinceTs = (int)($cached['max_ts'] ?? 0);

            // Prefer incremental fetch if your NostrClient supports it
            // e.g. getComments(string $coordinate, ?int $since = null): array
            $new = $this->nostrClient->getComments($coordinate, $sinceTs + 1);

            if (!empty($new)) {
                $merged = $this->mergeComments($cached['comments'], $new);
                [$profiles, $maxTs] = $this->hydrateProfilesAndTs($merged);

                $payload = [
                    'comments'  => $merged,
                    'profiles'  => $profiles,
                    'max_ts'    => $maxTs,
                    'stored_at' => time(),
                ];

                $this->redisCacheService->setCommentsPayload($coordinate, $payload, self::CACHE_TTL);
                $this->publish($coordinate, $merged, $profiles);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Incremental comments refresh failed', [
                'coord' => $coordinate, 'err' => $e->getMessage()
            ]);
        }
    }

    private function refreshFull(string $coordinate, ?array $cached): void
    {
        try {
            $comments = $this->nostrClient->getComments($coordinate);
            [$profiles, $maxTs] = $this->hydrateProfilesAndTs($comments);

            $payload = [
                'comments'  => $comments,
                'profiles'  => $profiles,
                'max_ts'    => $maxTs,
                'stored_at' => time(),
            ];

            $this->redisCacheService->setCommentsPayload($coordinate, $payload, self::CACHE_TTL);
            $this->publish($coordinate, $comments, $profiles);
        } catch (\Throwable $e) {
            $this->logger->error('Full comments refresh failed', [
                'coord' => $coordinate, 'err' => $e->getMessage()
            ]);

            // If we had *any* cache, at least publish that so clients see something
            if ($cached) {
                $this->publish($coordinate, $cached['comments'], $cached['profiles']);
            }
        }
    }

    /** Merge + sort desc by created_at, dedupe by id */
    private function mergeComments(array $existing, array $new): array
    {
        $byId = [];
        foreach ($existing as $c) { $byId[$c->id] = $c; }
        foreach ($new as $c)      { $byId[$c->id] = $c; }

        $all = array_values($byId);
        usort($all, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));
        return $all;
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
