<?php

namespace App\Controller\Api;

use App\Repository\ArticleRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

#[Route('/api')]
class ArticleBroadcastController extends AbstractController
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
        private readonly UserRelayListService $userRelayListService,
    ) {}

    /**
     * Broadcast an existing article to Nostr relays
     * Takes an article from the database and publishes it to specified relays without modifications
     */
    #[Route('/broadcast-article', name: 'api_broadcast_article', methods: ['POST'])]
    public function broadcastArticle(Request $request): JsonResponse
    {
        // Allow enough time for relay publishing (gateway timeout is 10s + per-relay settle)
        set_time_limit(60);

        try {
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid request data'
                ], 400);
            }

            // Accept either article ID or coordinate
            $articleId = $data['article_id'] ?? null;
            $coordinate = $data['coordinate'] ?? null;
            $relays = $data['relays'] ?? []; // Optional: specific relays to broadcast to
            // Default to user's write relays
            if (empty($relays)) {
                $user = $this->getUser();
                if (!$user) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Authentication required to broadcast articles'
                    ], 401);
                }
                // Fallback to UserRelayListService
                try {
                    $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                    $relays = $this->userRelayListService->getRelaysForPublishing($pubkeyHex);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to get user relays, using fallbacks', ['error' => $e->getMessage()]);
                    $relays = $this->userRelayListService->getFallbackRelays();
                }
            }

            // Find the article
            $article = null;

            if ($articleId) {
                // Find by database ID
                $article = $this->articleRepository->find($articleId);
            } elseif ($coordinate) {
                // Find by coordinate (kind:pubkey:slug)
                $parts = explode(':', $coordinate, 3);
                if (count($parts) === 3) {
                    [, $pubkey, $slug] = $parts;

                    $article = $this->articleRepository->createQueryBuilder('a')
                        ->where('a.pubkey = :pubkey')
                        ->andWhere('a.slug = :slug')
                        ->setParameter('pubkey', $pubkey)
                        ->setParameter('slug', $slug)
                        ->orderBy('a.createdAt', 'DESC')
                        ->setMaxResults(1)
                        ->getQuery()
                        ->getOneOrNullResult();
                }
            }

            if (!$article) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Article not found',
                    'article_id' => $articleId,
                    'coordinate' => $coordinate
                ], 404);
            }

            // Get the raw event data
            $rawEvent = $article->getRaw();

            if (!$rawEvent) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Article does not have raw event data',
                    'article_id' => $article->getId()
                ], 400);
            }

            // Reconstruct the Event object from raw data
            $event = Event::fromVerified((object)$rawEvent);

            $this->logger->info('Broadcasting article to relays', [
                'article_id' => $article->getId(),
                'event_id' => $event->getId(),
                'title' => $article->getTitle(),
                'pubkey' => substr($article->getPubkey(), 0, 8) . '...',
                'relay_count' => empty($relays) ? 'auto' : count($relays)
            ]);

            // Publish directly to relays (bypasses gateway, uses per-relay direct connections).
            $results = $this->nostrClient->publishEvent($event, $relays, 10);

            // Count successful broadcasts.
            // Results are either RelayResponseOk objects (successful relay response)
            // or ['ok' => bool, 'message' => string] arrays (per-relay result).
            $successCount = 0;
            $failedRelays = [];

            foreach ($results as $relay => $result) {
                $success = false;
                $message = '';

                if (is_object($result)) {
                    $success = (bool) ($result->isSuccess ?? $result->status ?? false);
                    $message = $result->message ?? '';
                } elseif (is_array($result)) {
                    $success = (bool) ($result['ok'] ?? false);
                    $message = $result['message'] ?? '';
                }

                if ($success) {
                    $successCount++;
                } else {
                    $failedRelays[] = [
                        'relay'   => $relay,
                        'error'   => $message ?: 'No confirmation received',
                    ];
                }
            }

            $this->logger->info('Article broadcast completed', [
                'article_id' => $article->getId(),
                'event_id' => $event->getId(),
                'total_relays' => count($results),
                'successful' => $successCount,
                'failed' => count($failedRelays)
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => 'Article broadcast to relays',
                'article' => [
                    'id' => $article->getId(),
                    'event_id' => $event->getId(),
                    'title' => $article->getTitle(),
                    'slug' => $article->getSlug(),
                    'pubkey' => $article->getPubkey()
                ],
                'broadcast' => [
                    'total_relays' => count($results),
                    'successful' => $successCount,
                    'failed' => count($failedRelays),
                    'failed_relays' => $failedRelays
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to broadcast article', [
                'article_id' => isset($article) ? $article->getId() : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to broadcast article: ' . $e->getMessage(),
                'article_id' => isset($article) ? $article->getId() : null
            ], 500);
        }
    }
}
