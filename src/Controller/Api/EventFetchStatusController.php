<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lightweight polling endpoint for async event-fetch status.
 *
 * The FetchEventFromRelaysHandler stores the result in Redis at
 * `event_fetch:{lookupKey}` with a 5-minute TTL. This endpoint lets the
 * Stimulus controller poll for the result when Mercure SSE is unavailable.
 */
class EventFetchStatusController extends AbstractController
{
    #[Route('/api/event-fetch-status/{lookupKey}', name: 'api_event_fetch_status', requirements: ['lookupKey' => '.+'], methods: ['GET'])]
    public function __invoke(
        string $lookupKey,
        \Redis $redis,
        LoggerInterface $logger,
    ): JsonResponse {
        $key = sprintf('event_fetch:%s', $lookupKey);

        try {
            $raw = $redis->get($key);
        } catch (\Throwable $e) {
            $logger->warning('Redis read failed for event fetch status', [
                'lookup_key' => $lookupKey,
                'error' => $e->getMessage(),
            ]);
            return $this->json(['status' => 'pending'], 200);
        }

        if ($raw === false) {
            return $this->json(['status' => 'pending'], 200);
        }

        $data = json_decode($raw, true);

        return $this->json($data ?? ['status' => 'pending'], 200);
    }
}

