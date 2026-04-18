<?php

namespace App\Controller\Newsroom;

use App\Entity\Highlight;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class HighlightController extends AbstractController
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly UserRelayListService $userRelayListService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/highlights/publish', name: 'highlight_publish', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
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

            // Must be kind 9802
            if ((int) $signedEvent['kind'] !== 9802) {
                return new JsonResponse(['error' => 'Event must be kind 9802 (highlight)'], 400);
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

            // Extract the article coordinate from tags ('a' tag with 30023: or 30024: prefix)
            $articleCoordinate = null;
            $context = null;
            foreach ($signedEvent['tags'] as $tag) {
                if (is_array($tag) && count($tag) >= 2) {
                    if (in_array($tag[0], ['a', 'A'])) {
                        if (str_starts_with($tag[1] ?? '', '30023:') || str_starts_with($tag[1] ?? '', '30024:')) {
                            $articleCoordinate = $tag[1];
                        }
                    }
                    if ($tag[0] === 'context' && isset($tag[1])) {
                        $context = $tag[1];
                    }
                }
            }

            if (!$articleCoordinate) {
                return new JsonResponse(['error' => 'No article reference (a tag) found in highlight'], 400);
            }

            // Parse the coordinate to get the article author's pubkey (kind:pubkey:slug)
            $colonPos = strpos($articleCoordinate, ':');
            $secondColonPos = strpos($articleCoordinate, ':', $colonPos + 1);
            $articleAuthorPubkey = substr($articleCoordinate, $colonPos + 1, $secondColonPos - $colonPos - 1);
            $highlighterPubkey = $signedEvent['pubkey'];

            $this->logger->info('Publishing highlight', [
                'highlight_id' => $signedEvent['id'],
                'article_coordinate' => $articleCoordinate,
                'article_author' => $articleAuthorPubkey,
                'highlighter' => $highlighterPubkey,
            ]);

            // Get relays for both the highlighter and the article author
            $relays = $this->collectRelaysForPublishing($highlighterPubkey, $articleAuthorPubkey);

            $this->logger->info('Publishing highlight to relays', [
                'highlight_id' => $signedEvent['id'],
                'relay_count' => count($relays),
                'relays' => $relays,
            ]);

            // Publish the highlight to all collected relays
            $relayResults = $this->nostrClient->publishEvent($eventObj, $relays);

            // Also persist locally for immediate display
            $this->persistHighlightLocally($signedEvent, $articleCoordinate, $context);

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

            $this->logger->info('Highlight published', [
                'highlight_id' => $signedEvent['id'],
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
            $this->logger->error('Error publishing highlight', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to publish highlight: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Persist the highlight to the local database for immediate display
     * without waiting for the next cron fetch cycle.
     */
    private function persistHighlightLocally(array $signedEvent, string $articleCoordinate, ?string $context): void
    {
        try {
            $repo = $this->entityManager->getRepository(Highlight::class);
            $existing = $repo->findOneBy(['eventId' => $signedEvent['id']]);
            if ($existing) {
                return; // Already exists
            }

            $highlight = new Highlight();
            $highlight->setEventId($signedEvent['id']);
            $highlight->setArticleCoordinate($articleCoordinate);
            $highlight->setContent($signedEvent['content'] ?? '');
            $highlight->setPubkey($signedEvent['pubkey'] ?? '');
            $highlight->setCreatedAt($signedEvent['created_at'] ?? time());
            $highlight->setContext($context);
            $highlight->setRawEvent($signedEvent);

            $this->entityManager->persist($highlight);
            $this->entityManager->flush();

            $this->logger->info('Highlight persisted locally', [
                'event_id' => $signedEvent['id'],
                'coordinate' => $articleCoordinate,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to persist highlight locally', [
                'event_id' => $signedEvent['id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Collect all relays for publishing the highlight
     */
    private function collectRelaysForPublishing(string $highlighterPubkey, string $articleAuthorPubkey): array
    {
        $relays = [];

        // Get relays for the highlighter
        try {
            $highlighterRelays = $this->userRelayListService->getRelaysForPublishing($highlighterPubkey);
            foreach ($highlighterRelays as $relay) {
                if (!in_array($relay, $relays)) {
                    $relays[] = $relay;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get highlighter relays', [
                'pubkey' => $highlighterPubkey,
                'error' => $e->getMessage(),
            ]);
        }

        // Get relays for the article author
        try {
            $articleAuthorRelays = $this->userRelayListService->getRelaysForPublishing($articleAuthorPubkey);
            foreach ($articleAuthorRelays as $relay) {
                if (!in_array($relay, $relays)) {
                    $relays[] = $relay;
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get article author relays', [
                'pubkey' => $articleAuthorPubkey,
                'error' => $e->getMessage(),
            ]);
        }

        // If no relays were collected, use fallback relays
        if (empty($relays)) {
            $relays = $this->userRelayListService->getFallbackRelays();
        }

        return $relays;
    }
}

