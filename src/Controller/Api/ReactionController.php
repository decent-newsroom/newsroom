<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\KindsEnum;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/reactions')]
final class ReactionController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/current', name: 'api_reactions_current', methods: ['GET'])]
    public function current(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $coordinate = trim((string) $request->query->get('coordinate', ''));
        if (!$this->isValidCoordinate($coordinate)) {
            return new JsonResponse(['error' => 'Invalid coordinate'], 400);
        }

        $params = $this->buildCoordinateTagParams($coordinate);
        $refCondition = '(e.tags @> CAST(:aTag AS jsonb) OR e.tags @> CAST(:upperATag AS jsonb))';

        $conn = $em->getConnection();
        $count = (int) $conn->executeQuery(
            "SELECT COUNT(DISTINCT e.pubkey)
             FROM event e
             WHERE e.kind = :kind
               AND e.content = '+'
               AND {$refCondition}",
            ['kind' => KindsEnum::REACTION->value] + $params,
        )->fetchOne();

        $liked = false;
        $user = $this->getUser();
        if ($user !== null) {
            try {
                $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $liked = (bool) $conn->executeQuery(
                    "SELECT 1
                     FROM event e
                     WHERE e.kind = :kind
                       AND e.content = '+'
                       AND e.pubkey = :pubkey
                       AND {$refCondition}
                     LIMIT 1",
                    ['kind' => KindsEnum::REACTION->value, 'pubkey' => $pubkey] + $params,
                )->fetchOne();
            } catch (\Throwable) {
                $liked = false;
            }
        }

        return new JsonResponse([
            'liked' => $liked,
            'count' => $count,
        ]);
    }

    #[Route('/publish', name: 'api_reactions_publish', methods: ['POST'])]
    public function publish(
        Request $request,
        NostrClient $nostrClient,
        UserRelayListService $userRelayListService,
        GenericEventProjector $eventProjector,
        EntityManagerInterface $em,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!is_array($data) || !isset($data['event']) || !is_array($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];
            foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'] as $field) {
                if (!array_key_exists($field, $signedEvent)) {
                    return new JsonResponse(['error' => 'Missing required event fields'], 400);
                }
            }
            if (!is_array($signedEvent['tags'])) {
                return new JsonResponse(['error' => 'Invalid reaction tags'], 400);
            }

            if ((int) $signedEvent['kind'] !== KindsEnum::REACTION->value) {
                return new JsonResponse(['error' => 'Invalid reaction event kind'], 400);
            }

            if ((string) $signedEvent['content'] !== '+') {
                return new JsonResponse(['error' => 'Only positive article reactions are supported here'], 400);
            }

            $coordinate = $this->extractCoordinate($signedEvent['tags']);
            if ($coordinate === null || !$this->isValidCoordinate($coordinate)) {
                return new JsonResponse(['error' => 'Reaction must reference an article coordinate'], 400);
            }

            $eventObj = new NostrEvent();
            $eventObj->setId((string) $signedEvent['id']);
            $eventObj->setPublicKey((string) $signedEvent['pubkey']);
            $eventObj->setCreatedAt((int) $signedEvent['created_at']);
            $eventObj->setKind((int) $signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags']);
            $eventObj->setContent((string) $signedEvent['content']);
            $eventObj->setSignature((string) $signedEvent['sig']);

            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $rawEvent = (object) [
                'id' => (string) $signedEvent['id'],
                'pubkey' => (string) $signedEvent['pubkey'],
                'created_at' => (int) $signedEvent['created_at'],
                'kind' => (int) $signedEvent['kind'],
                'tags' => $signedEvent['tags'],
                'content' => (string) $signedEvent['content'],
                'sig' => (string) $signedEvent['sig'],
            ];
            $eventProjector->projectEventFromNostrEvent($rawEvent, 'local');

            $articleAuthorPubkey = explode(':', $coordinate, 3)[1];
            $relays = $this->collectRelaysForPublishing(
                (string) $signedEvent['pubkey'],
                $articleAuthorPubkey,
                $userRelayListService,
            );

            $this->logger->info('Publishing article reaction', [
                'event_id' => $signedEvent['id'],
                'coordinate' => $coordinate,
                'relay_count' => count($relays),
            ]);

            $relayResults = $nostrClient->publishEvent($eventObj, $relays);
            $successCount = 0;
            $failCount = 0;
            $relayStatuses = [];

            foreach ($relayResults as $relayUrl => $result) {
                $isSuccess = $result === true || (is_object($result) && isset($result->type) && $result->type === 'OK');
                $isSuccess ? $successCount++ : $failCount++;
                $relayStatuses[$relayUrl] = $isSuccess ? 'ok' : 'failed';
            }

            return new JsonResponse([
                'status' => 'ok',
                'event_id' => $signedEvent['id'],
                'count' => $this->countLikes($coordinate, $em),
                'relays' => [
                    'total' => count($relays),
                    'success' => $successCount,
                    'failed' => $failCount,
                    'statuses' => $relayStatuses,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Error publishing article reaction', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to publish reaction: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @param array<int, mixed> $tags
     */
    private function extractCoordinate(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            if (($tag[0] === 'a' || $tag[0] === 'A') && is_string($tag[1])) {
                return $tag[1];
            }
        }

        return null;
    }

    private function isValidCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);

        return count($parts) === 3
            && ctype_digit($parts[0])
            && preg_match('/^[0-9a-f]{64}$/', $parts[1]) === 1
            && $parts[2] !== '';
    }

    /**
     * @return array{aTag:string, upperATag:string}
     */
    private function buildCoordinateTagParams(string $coordinate): array
    {
        return [
            'aTag' => json_encode([['a', $coordinate]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'upperATag' => json_encode([['A', $coordinate]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    private function countLikes(string $coordinate, EntityManagerInterface $em): int
    {
        $params = $this->buildCoordinateTagParams($coordinate);
        $conn = $em->getConnection();

        return (int) $conn->executeQuery(
            "SELECT COUNT(DISTINCT e.pubkey)
             FROM event e
             WHERE e.kind = :kind
               AND e.content = '+'
               AND (e.tags @> CAST(:aTag AS jsonb) OR e.tags @> CAST(:upperATag AS jsonb))",
            ['kind' => KindsEnum::REACTION->value] + $params,
        )->fetchOne();
    }

    /**
     * @return string[]
     */
    private function collectRelaysForPublishing(
        string $reactorPubkey,
        string $articleAuthorPubkey,
        UserRelayListService $userRelayListService,
    ): array {
        $relays = [];

        foreach ([$reactorPubkey, $articleAuthorPubkey] as $pubkey) {
            try {
                foreach ($userRelayListService->getRelaysForPublishing($pubkey) as $relay) {
                    if (!in_array($relay, $relays, true)) {
                        $relays[] = $relay;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to get relays for reaction publish', [
                    'pubkey' => substr($pubkey, 0, 8) . '...',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($relays === []) {
            $relays = $userRelayListService->getFallbackRelays();
        }

        return array_values(array_unique($relays));
    }
}
