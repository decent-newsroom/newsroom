<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoint for publishing feedback events (kind 24).
 *
 * The feedback form (/feedback) signs a kind 24 event client-side and
 * POSTs it here. The controller verifies the signature, persists the
 * event locally via GenericEventProjector, and publishes it to the
 * local relay so admins can review it.
 */
class FeedbackApiController extends AbstractController
{
    #[Route('/api/nostr/publish', name: 'api_nostr_publish', methods: ['POST'])]
    public function publish(
        Request $request,
        NostrClient $nostrClient,
        GenericEventProjector $genericEventProjector,
        LoggerInterface $logger,
        ?string $nostrDefaultRelay = null,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            // Validate required fields
            if (!isset(
                $signedEvent['id'],
                $signedEvent['pubkey'],
                $signedEvent['created_at'],
                $signedEvent['kind'],
                $signedEvent['sig'],
            )) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Only allow kind 24 (feedback) through this endpoint
            if ((int) $signedEvent['kind'] !== 24) {
                return new JsonResponse(['error' => 'Only kind 24 (feedback) events are accepted'], 400);
            }

            // Build a verifiable event object
            $eventObj = new Event();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags'] ?? []);
            $eventObj->setContent($signedEvent['content'] ?? '');
            $eventObj->setSignature($signedEvent['sig']);

            // Verify the event signature
            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $logger->info('Received feedback event (kind 24)', [
                'event_id' => $signedEvent['id'],
                'pubkey'   => $signedEvent['pubkey'],
            ]);

            // Persist locally via GenericEventProjector
            $genericEventProjector->projectEventFromNostrEvent(
                (object) $signedEvent,
                $nostrDefaultRelay ?? 'feedback-form',
            );

            // Publish to the local relay only
            $relays = $nostrDefaultRelay ? [$nostrDefaultRelay] : [];
            $relayResults = [];
            if (!empty($relays)) {
                $relayResults = $nostrClient->publishEvent($eventObj, $relays);
            }

            $successCount = 0;
            foreach ($relayResults as $result) {
                if ($result === true || (is_object($result) && isset($result->type) && $result->type === 'OK')) {
                    $successCount++;
                }
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Feedback sent. Thank you!',
                'relays_success' => $successCount,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Feedback publish error', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Publishing failed: ' . $e->getMessage()], 500);
        }
    }
}

