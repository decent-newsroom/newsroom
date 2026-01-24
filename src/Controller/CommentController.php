<?php
namespace App\Controller;

use App\Service\AuthorRelayService;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class CommentController extends AbstractController
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly AuthorRelayService $authorRelayService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/comments/publish', name: 'comment_publish', methods: ['POST'])]
    public function publish(Request $request): Response
    {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            // Validate required fields
            if (!isset($signedEvent['id'], $signedEvent['pubkey'], $signedEvent['created_at'],
                       $signedEvent['kind'], $signedEvent['tags'], $signedEvent['content'], $signedEvent['sig'])) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Convert the signed event array to a proper Event object for verification
            $eventObj = new Event();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags']);
            $eventObj->setContent($signedEvent['content']);
            $eventObj->setSignature($signedEvent['sig']);

            // Verify the event signature
            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            // Extract the A tag (uppercase) containing the article coordinate
            $articleCoordinate = null;
            foreach ($signedEvent['tags'] as $tag) {
                if (is_array($tag) && count($tag) >= 2 && $tag[0] === 'A') {
                    $articleCoordinate = $tag[1];
                    break;
                }
            }

            if (!$articleCoordinate) {
                return new JsonResponse(['error' => 'No article reference (A tag) found in comment'], 400);
            }

            // Parse the coordinate to get the article author's pubkey
            // Format: kind:pubkey:identifier
            $coordinateParts = explode(':', $articleCoordinate, 3);
            if (count($coordinateParts) < 3) {
                return new JsonResponse(['error' => 'Invalid article coordinate format'], 400);
            }

            $articleAuthorPubkey = $coordinateParts[1];
            $commenterPubkey = $signedEvent['pubkey'];

            $this->logger->info('Publishing comment', [
                'comment_id' => $signedEvent['id'],
                'article_coordinate' => $articleCoordinate,
                'article_author' => $articleAuthorPubkey,
                'commenter' => $commenterPubkey,
            ]);

            // Get relays for both the commenter and the article author
            $relays = $this->collectRelaysForPublishing($commenterPubkey, $articleAuthorPubkey);

            $this->logger->info('Publishing comment to relays', [
                'comment_id' => $signedEvent['id'],
                'relay_count' => count($relays),
                'relays' => $relays,
            ]);

            // Publish the comment to all collected relays
            $relayResults = $this->nostrClient->publishEvent($eventObj, $relays);

            // Transform results for response
            $successCount = 0;
            $failCount = 0;
            $relayStatuses = [];

            foreach ($relayResults as $relayUrl => $result) {
                $isSuccess = $result === true || (is_object($result) && isset($result->type) && $result->type === 'OK');
                if ($isSuccess) {
                    $successCount++;
                } else {
                    $failCount++;
                }
                $relayStatuses[$relayUrl] = $isSuccess ? 'ok' : 'failed';
            }

            $this->logger->info('Comment published', [
                'comment_id' => $signedEvent['id'],
                'success_count' => $successCount,
                'fail_count' => $failCount,
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'event_id' => $signedEvent['id'],
                'relays' => [
                    'total' => count($relays),
                    'success' => $successCount,
                    'failed' => $failCount,
                    'statuses' => $relayStatuses,
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error publishing comment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to publish comment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Collect all relays for publishing the comment
     * - All known relays of the commenter (npub publishing the comment)
     * - All known relays of the article author (npub that authored the article)
     */
    private function collectRelaysForPublishing(string $commenterPubkey, string $articleAuthorPubkey): array
    {
        $relays = [];
        $key = new Key();

        // Get relays for the commenter
        try {
            $commenterRelays = $this->authorRelayService->getRelaysForPublishing($commenterPubkey);
            foreach ($commenterRelays as $relay) {
                if (!in_array($relay, $relays)) {
                    $relays[] = $relay;
                }
            }
            $this->logger->debug('Added commenter relays', [
                'pubkey' => $commenterPubkey,
                'relay_count' => count($commenterRelays),
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get commenter relays', [
                'pubkey' => $commenterPubkey,
                'error' => $e->getMessage(),
            ]);
        }

        // Get relays for the article author
        try {
            $articleAuthorRelays = $this->authorRelayService->getRelaysForPublishing($articleAuthorPubkey);
            foreach ($articleAuthorRelays as $relay) {
                if (!in_array($relay, $relays)) {
                    $relays[] = $relay;
                }
            }
            $this->logger->debug('Added article author relays', [
                'pubkey' => $articleAuthorPubkey,
                'relay_count' => count($articleAuthorRelays),
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get article author relays', [
                'pubkey' => $articleAuthorPubkey,
                'error' => $e->getMessage(),
            ]);
        }

        // If no relays were collected, use fallback relays
        if (empty($relays)) {
            $relays = $this->authorRelayService->getFallbackRelays();
            $this->logger->info('Using fallback relays', [
                'relay_count' => count($relays),
            ]);
        }

        return $relays;
    }
}

