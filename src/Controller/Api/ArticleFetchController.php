<?php

namespace App\Controller\Api;

use App\Enum\KindsEnum;
use App\Service\ArticleEventProjector;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ArticleFetchController extends AbstractController
{
    private const ARTICLE_KINDS = [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value];

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly ArticleEventProjector $articleProjector,
        private readonly GenericEventProjector $genericEventProjector,
    ) {}

    /**
     * Endpoint to fetch an event that hasn't been fully loaded.
     * Supports lookup by coordinate (a-tag references) or event ID (e-tag references).
     * This is called by the CardPlaceholder component when user clicks "Fetch Article".
     */
    #[Route('/fetch-article', name: 'api_fetch_article', methods: ['POST'])]
    public function fetchArticle(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid request data'
            ], 400);
        }

        $id = $data['id'] ?? null;
        $pubkey = $data['pubkey'] ?? null;
        $slug = $data['slug'] ?? null;
        $kind = $data['kind'] ?? null;
        $coordinate = $data['coordinate'] ?? null;

        // Build coordinate from parts if not provided directly
        if (!$coordinate && $pubkey && $slug) {
            $coordinate = ($kind ?: '30023') . ":{$pubkey}:{$slug}";
        }

        // Fetch by event ID when no coordinate is available
        if (!$coordinate) {
            if (!$id) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameters: coordinate, (pubkey and slug), or id'
                ], 400);
            }

            return $this->fetchByEventId($id);
        }

        return $this->fetchByCoordinate($coordinate);
    }

    /**
     * Fetch an event by its coordinate (kind:pubkey:slug) from Nostr relays.
     */
    private function fetchByCoordinate(string $coordinate): JsonResponse
    {
        try {
            $articlesMap = $this->nostrClient->getArticlesByCoordinates([$coordinate]);

            if (empty($articlesMap)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Event not found on Nostr relays',
                    'coordinate' => $coordinate
                ], 404);
            }

            $event = $articlesMap[$coordinate] ?? null;

            if (!$event) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Event not found in response',
                    'coordinate' => $coordinate
                ], 404);
            }

            $this->projectEvent($event);

            return new JsonResponse([
                'success' => true,
                'message' => 'Event successfully fetched from Nostr and saved to database',
                'coordinate' => $coordinate,
                'event_id' => $event->id ?? null
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to fetch event: ' . $e->getMessage(),
                'coordinate' => $coordinate
            ], 500);
        }
    }

    /**
     * Fetch an event by its ID from Nostr relays.
     */
    private function fetchByEventId(string $id): JsonResponse
    {
        try {
            $eventsMap = $this->nostrClient->getEventsByIds([$id]);

            if (empty($eventsMap)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Event not found on Nostr relays',
                    'event_id' => $id
                ], 404);
            }

            $event = $eventsMap[$id] ?? null;

            if (!$event) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Event not found in response',
                    'event_id' => $id
                ], 404);
            }

            $this->projectEvent($event);

            return new JsonResponse([
                'success' => true,
                'message' => 'Event successfully fetched from Nostr and saved to database',
                'event_id' => $event->id ?? null
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to fetch event: ' . $e->getMessage(),
                'event_id' => $id
            ], 500);
        }
    }

    /**
     * Project the fetched event using the appropriate projector based on its kind.
     */
    private function projectEvent(object $event): void
    {
        $eventKind = (int) ($event->kind ?? 0);

        if (in_array($eventKind, self::ARTICLE_KINDS, true)) {
            $this->articleProjector->projectArticleFromEvent($event, 'api-fetch');
        } else {
            $this->genericEventProjector->projectEventFromNostrEvent($event, 'api-fetch');
        }
    }
}
