<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Nostr\Nip46SessionService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives NIP-46 signer session credentials from the browser after a successful
 * remote-signer login and stores them server-side so the relay gateway can sign
 * NIP-42 AUTH events without requiring a browser roundtrip.
 *
 * The credentials stored here are:
 *   - clientPrivkeyHex  — the *ephemeral* per-session client key used for NIP-46
 *                         RPC encryption (kind:24133), NOT the user's nsec.
 *   - bunkerPubkeyHex   — the remote signer's Nostr pubkey
 *   - bunkerRelays      — relay URLs where the bunker is reachable
 *
 * Stored encrypted with AES-256-GCM keyed by APP_ENCRYPTION_KEY, with an 8-hour TTL.
 */
class Nip46SessionController extends AbstractController
{
    public function __construct(
        private readonly Nip46SessionService $sessionService,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/nostr-connect/session', name: 'api_nostr_connect_session', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $clientPrivkeyHex = $body['clientPrivkeyHex'] ?? null;
        $bunkerPubkeyHex  = $body['bunkerPubkeyHex']  ?? null;
        $bunkerRelays     = $body['bunkerRelays']      ?? null;

        if (!is_string($clientPrivkeyHex) || strlen($clientPrivkeyHex) !== 64) {
            return new JsonResponse(['error' => 'Missing or invalid clientPrivkeyHex (must be 32-byte hex)'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_string($bunkerPubkeyHex) || strlen($bunkerPubkeyHex) !== 64) {
            return new JsonResponse(['error' => 'Missing or invalid bunkerPubkeyHex (must be 32-byte hex)'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($bunkerRelays) || empty($bunkerRelays)) {
            return new JsonResponse(['error' => 'Missing or empty bunkerRelays'], Response::HTTP_BAD_REQUEST);
        }

        // Sanitise relays to strings only
        $bunkerRelays = array_values(array_filter(
            array_map('strval', $bunkerRelays),
            static fn(string $r) => str_starts_with($r, 'wss://') || str_starts_with($r, 'ws://'),
        ));

        if (empty($bunkerRelays)) {
            return new JsonResponse(['error' => 'No valid relay URLs in bunkerRelays'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $userPubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            $this->logger->warning('Nip46SessionController: failed to parse user pubkey', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Invalid user identity'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->sessionService->store($userPubkeyHex, $clientPrivkeyHex, $bunkerPubkeyHex, $bunkerRelays);
        } catch (\Throwable $e) {
            $this->logger->error('Nip46SessionController: failed to store session', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Failed to store session'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->logger->info('Nip46SessionController: stored remote signer session', [
            'pubkey'       => substr($userPubkeyHex, 0, 8) . '...',
            'relay_count'  => count($bunkerRelays),
        ]);

        return new JsonResponse(['status' => 'ok'], Response::HTTP_CREATED);
    }
}

