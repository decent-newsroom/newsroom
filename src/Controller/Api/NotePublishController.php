<?php

namespace App\Controller\Api;

use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class NotePublishController extends AbstractController
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly UserRelayListService $userRelayListService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Publish a signed kind 1 note to the user's write relays.
     */
    #[Route('/note/publish', name: 'api_note_publish', methods: ['POST'])]
    public function publishNote(Request $request): JsonResponse
    {
        set_time_limit(30);

        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'Authentication required'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['event'])) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid request data'], 400);
        }

        $signedEvent = $data['event'];

        // Only allow kind 1 notes
        if (($signedEvent['kind'] ?? null) !== 1) {
            return new JsonResponse(['success' => false, 'error' => 'Only kind 1 notes are accepted'], 400);
        }

        try {
            $eventObj = Event::fromVerified((object) $signedEvent);

            if (!$eventObj->verify()) {
                return new JsonResponse(['success' => false, 'error' => 'Event signature verification failed'], 400);
            }
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid event: ' . $e->getMessage()], 400);
        }

        // Resolve write relays
        try {
            $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $relays = $this->userRelayListService->getRelaysForPublishing($pubkeyHex);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get user relays for note publish', ['error' => $e->getMessage()]);
            $relays = $this->userRelayListService->getFallbackRelays();
        }

        try {
            $results = $this->nostrClient->publishEvent($eventObj, $relays, 10);

            $successCount = 0;
            foreach ($results as $result) {
                $ok = is_object($result)
                    ? (bool) ($result->isSuccess ?? $result->status ?? false)
                    : (bool) ($result['ok'] ?? false);
                if ($ok) {
                    $successCount++;
                }
            }

            return new JsonResponse([
                'success' => true,
                'relayCount' => count($results),
                'successCount' => $successCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish note to relays', ['error' => $e->getMessage()]);
            return new JsonResponse(['success' => false, 'error' => 'Relay publishing failed: ' . $e->getMessage()], 500);
        }
    }
}

