<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoint for the NIP-42 AUTH Mercure roundtrip.
 *
 * The relay gateway pushes AUTH challenges to the user's browser via Mercure SSE.
 * The browser's relay_auth_controller.js signs the challenge with getSigner()
 * and POSTs the signed kind 22242 event here. We store it in Redis so the
 * gateway can pick it up and complete the AUTH handshake.
 */
class RelayAuthController extends AbstractController
{
    private const SIGNED_KEY_PREFIX = 'relay_auth_signed:';
    private const PENDING_KEY_PREFIX = 'relay_auth_pending:';
    private const TTL = 60; // seconds

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/relay-auth/pending', name: 'api_relay_auth_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['items' => []], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\Throwable) {
            return new JsonResponse(['items' => []], Response::HTTP_BAD_REQUEST);
        }

        try {
            $keys = $this->redis->keys(self::PENDING_KEY_PREFIX . '*') ?: [];
        } catch (\RedisException $e) {
            $this->logger->error('RelayAuthController: failed to enumerate pending AUTH keys', [
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['items' => []], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $items = [];
        foreach ($keys as $key) {
            $payload = $this->redis->get($key);
            if (!is_string($payload) || $payload === '') {
                continue;
            }

            $pending = json_decode($payload, true);
            if (!is_array($pending) || ($pending['pubkey'] ?? null) !== $pubkey) {
                continue;
            }

            $items[] = [
                'requestId' => substr((string) $key, strlen(self::PENDING_KEY_PREFIX)),
                'relay' => $pending['relay'] ?? null,
                'challenge' => $pending['challenge'] ?? null,
                'createdAt' => $pending['created_at'] ?? null,
            ];
        }

        usort($items, static fn (array $a, array $b) => (int) ($a['createdAt'] ?? 0) <=> (int) ($b['createdAt'] ?? 0));

        return new JsonResponse([
            'items' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * Receive a signed NIP-42 AUTH event from the browser.
     *
     * The gateway polls `relay_auth_signed:{requestId}` to pick up the response.
     */
    #[Route('/api/relay-auth/{requestId}', name: 'api_relay_auth', methods: ['POST'])]
    public function submitSignedAuth(string $requestId, Request $request): JsonResponse
    {
        $this->logger->debug('RelayAuthController: received signed AUTH', [
            'request_id' => $requestId,
        ]);

        // Parse the signed event from the request body
        $body = json_decode($request->getContent(), true);
        if (!$body || !isset($body['signedEvent'])) {
            return new JsonResponse(['error' => 'Missing signedEvent in request body'], Response::HTTP_BAD_REQUEST);
        }

        $signedEvent = $body['signedEvent'];

        // Validate basic structure
        if (!isset($signedEvent['kind']) || (int) $signedEvent['kind'] !== 22242) {
            return new JsonResponse(['error' => 'Invalid event kind, expected 22242'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($signedEvent['sig']) || empty($signedEvent['sig'])) {
            return new JsonResponse(['error' => 'Event is not signed'], Response::HTTP_BAD_REQUEST);
        }

        // Validate against the pending challenge (if it exists)
        try {
            $pendingKey = self::PENDING_KEY_PREFIX . $requestId;
            $pendingJson = $this->redis->get($pendingKey);

            if ($pendingJson === false) {
                $this->logger->warning('RelayAuthController: no pending challenge found (expired or invalid)', [
                    'request_id' => $requestId,
                ]);
                return new JsonResponse(['error' => 'Challenge expired or not found'], Response::HTTP_GONE);
            }

            $pending = json_decode($pendingJson, true);

            // Verify the signed event's relay tag matches the pending challenge
            $relayTag = null;
            $challengeTag = null;
            foreach ($signedEvent['tags'] ?? [] as $tag) {
                if (($tag[0] ?? '') === 'relay') {
                    $relayTag = $tag[1] ?? null;
                }
                if (($tag[0] ?? '') === 'challenge') {
                    $challengeTag = $tag[1] ?? null;
                }
            }

            if ($relayTag !== ($pending['relay'] ?? null)) {
                $this->logger->warning('RelayAuthController: relay tag mismatch', [
                    'request_id' => $requestId,
                    'expected' => $pending['relay'] ?? null,
                    'got' => $relayTag,
                ]);
                return new JsonResponse(['error' => 'Relay tag mismatch'], Response::HTTP_BAD_REQUEST);
            }

            if ($challengeTag !== ($pending['challenge'] ?? null)) {
                $this->logger->warning('RelayAuthController: challenge tag mismatch', [
                    'request_id' => $requestId,
                    'expected' => $pending['challenge'] ?? null,
                    'got' => $challengeTag,
                ]);
                return new JsonResponse(['error' => 'Challenge tag mismatch'], Response::HTTP_BAD_REQUEST);
            }

            // Store the signed event for the gateway to pick up
            $signedKey = self::SIGNED_KEY_PREFIX . $requestId;
            $this->redis->set($signedKey, json_encode($signedEvent), ['ex' => self::TTL]);

            // Clean up the pending challenge
            $this->redis->del($pendingKey);

            $this->logger->info('RelayAuthController: stored signed AUTH for gateway', [
                'request_id' => $requestId,
                'relay' => $relayTag,
                'pubkey' => substr($signedEvent['pubkey'] ?? 'unknown', 0, 8) . '...',
            ]);

            return new JsonResponse(['status' => 'ok']);

        } catch (\RedisException $e) {
            $this->logger->error('RelayAuthController: Redis error', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

