<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Dto\NormalizedMedia;
use App\Service\Nostr\MediaEventService;
use Psr\Log\LoggerInterface;

/**
 * Queries relays for published media events (kinds 20, 21, 22)
 * and normalizes them into NormalizedMedia objects.
 *
 * @see §5.3 of multimedia-manager spec
 */
class MediaRelayQueryService
{
    public function __construct(
        private readonly MediaEventService $mediaEventService,
        private readonly MediaMetadataNormalizer $normalizer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Fetch published media posts for an author from relays.
     *
     * @param string $pubkey Hex pubkey
     * @param int[]  $kinds  Kinds to query (default: [20, 21, 22])
     * @param int    $limit  Max results
     * @return NormalizedMedia[]
     */
    public function fetchPostsForAuthor(string $pubkey, array $kinds = [20, 21, 22], int $limit = 50): array
    {
        try {
            $events = $this->mediaEventService->fetchForPubkey($pubkey, $kinds, $limit);

            $this->logger->debug('Fetched media events from relays', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'count' => count($events),
                'kinds' => $kinds,
            ]);

            return $this->normalizer->normalizeEvents($events);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch media posts from relays', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Fetch recent media posts from default relay set (no author filter).
     *
     * @return NormalizedMedia[]
     */
    public function fetchRecent(int $limit = 50): array
    {
        try {
            $events = $this->mediaEventService->fetchRecent($limit);
            return $this->normalizer->normalizeEvents($events);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch recent media posts', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Filter normalized media by kind.
     *
     * @param NormalizedMedia[] $media
     * @param string            $filter 'all', 'pictures', 'videos', 'short-videos'
     * @return NormalizedMedia[]
     */
    public function filterByType(array $media, string $filter): array
    {
        return match ($filter) {
            'pictures' => array_filter($media, fn(NormalizedMedia $m) => $m->kind === 20),
            'videos' => array_filter($media, fn(NormalizedMedia $m) => $m->kind === 21),
            'short-videos' => array_filter($media, fn(NormalizedMedia $m) => $m->kind === 22),
            default => $media,
        };
    }
}

