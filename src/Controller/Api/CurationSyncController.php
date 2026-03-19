<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Event;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CurationSyncController extends AbstractController
{
    public function __construct(
        private readonly \Redis $redis,
    ) {}

    #[Route('/api/curation/{curationId}/media-sync-status', name: 'api_curation_media_sync_status', methods: ['GET'])]
    public function status(string $curationId, EventRepository $eventRepository): JsonResponse
    {
        $curation = $eventRepository->findById($curationId);
        if (!$curation instanceof Event) {
            return new JsonResponse(['pending' => false, 'missingCount' => 0], 404);
        }

        $eventIds = [];
        $coordinates = [];
        foreach ($curation->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1]) || !is_string($tag[1])) {
                continue;
            }
            if ($tag[0] === 'e') {
                $eventIds[] = $tag[1];
            } elseif ($tag[0] === 'a') {
                $coordinates[] = $tag[1];
            }
        }

        $eventIds = array_values(array_unique($eventIds));
        $coordinates = array_values(array_unique($coordinates));

        // Check e-tag event IDs
        $missingIds = [];
        $foundIdCount = 0;
        if ($eventIds !== []) {
            $foundIds = array_map(
                static fn (Event $event) => $event->getId(),
                $eventRepository->findBy(['id' => $eventIds])
            );
            $missingIds = array_values(array_diff($eventIds, $foundIds));
            $foundIdCount = count($foundIds);
        }

        // Check a-tag coordinates
        $missingCoordinates = [];
        $foundCoordCount = 0;
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$kind, $pubkey, $identifier] = $parts;
            $existing = $eventRepository->findByNaddr((int)$kind, $pubkey, $identifier);
            if ($existing) {
                $foundCoordCount++;
            } else {
                $missingCoordinates[] = $coord;
            }
        }

        $totalMissing = count($missingIds) + count($missingCoordinates);
        $totalFound = $foundIdCount + $foundCoordCount;
        $totalItems = count($eventIds) + count($coordinates);

        // Read fetch attempt metadata from Redis
        $fetchAttempt = null;
        try {
            $raw = $this->redis->get(sprintf('curation_sync:%s', $curationId));
            if ($raw) {
                $fetchAttempt = json_decode($raw, true);
            }
        } catch (\Throwable) {}

        return new JsonResponse([
            'pending' => $totalMissing > 0,
            'missingCount' => $totalMissing,
            'foundCount' => $totalFound,
            'totalCount' => $totalItems,
            'missingIds' => $missingIds,
            'missingCoordinates' => $missingCoordinates,
            'fetchAttempt' => $fetchAttempt,
        ]);
    }
}

