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
        foreach ($curation->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'e' && isset($tag[1]) && is_string($tag[1])) {
                $eventIds[] = $tag[1];
            }
        }

        $eventIds = array_values(array_unique($eventIds));
        if ($eventIds === []) {
            return new JsonResponse(['pending' => false, 'missingCount' => 0]);
        }

        $foundIds = array_map(
            static fn (Event $event) => $event->getId(),
            $eventRepository->findBy(['id' => $eventIds])
        );
        $missingIds = array_values(array_diff($eventIds, $foundIds));

        // Read fetch attempt metadata from Redis
        $fetchAttempt = null;
        try {
            $raw = $this->redis->get(sprintf('curation_sync:%s', $curationId));
            if ($raw) {
                $fetchAttempt = json_decode($raw, true);
            }
        } catch (\Throwable) {}

        return new JsonResponse([
            'pending' => $missingIds !== [],
            'missingCount' => count($missingIds),
            'foundCount' => count($foundIds),
            'totalCount' => count($eventIds),
            'missingIds' => $missingIds,
            'fetchAttempt' => $fetchAttempt,
        ]);
    }
}

