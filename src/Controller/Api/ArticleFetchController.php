<?php

namespace App\Controller\Api;

use App\Service\ArticleEventProjector;
use App\Service\Nostr\NostrClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class ArticleFetchController extends AbstractController
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly ArticleEventProjector $articleProjector
    ) {}

    /**
     * Endpoint to fetch an article that hasn't been fully loaded
     * This is called by the CardPlaceholder component when user clicks "Fetch Article"
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

        $pubkey = $data['pubkey'] ?? null;
        $slug = $data['slug'] ?? null;
        $coordinate = $data['coordinate'] ?? null;

        // Parse coordinate if provided
        if ($coordinate && str_contains($coordinate, ':')) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) === 3) {
                [, $pubkey, $slug] = $parts;
                // Use the full coordinate as-is
            }
        } else if ($pubkey && $slug) {
            // Build coordinate from parts (default to kind 30023)
            $coordinate = "30023:{$pubkey}:{$slug}";
        }

        if (!$coordinate) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required parameters: coordinate or (pubkey and slug)'
            ], 400);
        }

        try {
            // Article not in database - fetch from Nostr relays
            $articlesMap = $this->nostrClient->getArticlesByCoordinates([$coordinate]);

            if (empty($articlesMap)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Article not found on Nostr relays',
                    'coordinate' => $coordinate
                ], 404);
            }

            // Get the fetched event
            $event = $articlesMap[$coordinate] ?? null;

            if (!$event) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Article not found in response',
                    'coordinate' => $coordinate
                ], 404);
            }

            // Project the event into the database
            $this->articleProjector->projectArticleFromEvent($event, 'api-fetch');

            return new JsonResponse([
                'success' => true,
                'message' => 'Article successfully fetched from Nostr and saved to database',
                'coordinate' => $coordinate,
                'event_id' => $event->id ?? null
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to fetch article: ' . $e->getMessage(),
                'coordinate' => $coordinate ?? 'unknown'
            ], 500);
        }
    }
}
