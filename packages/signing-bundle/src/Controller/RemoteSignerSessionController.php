<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Controller;

use DecentNewsroom\SigningBundle\Contract\CurrentSubjectPubkeyResolverInterface;
use DecentNewsroom\SigningBundle\Dto\RemoteSignerSession;
use DecentNewsroom\SigningBundle\Service\Nostr\Nip46SessionStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RemoteSignerSessionController
{
    public function __construct(
        private Nip46SessionStore $sessions,
        private CurrentSubjectPubkeyResolverInterface $subjectPubkeys,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/nostr-connect/session', name: 'api_nostr_connect_session', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $subjectPubkeyHex = $this->subjectPubkeys->resolveCurrentSubjectPubkeyHex();
        if ($subjectPubkeyHex === null) {
            return new JsonResponse(['error' => 'Authentication required'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $data = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $clientPrivkeyHex = (string) ($data['clientPrivkeyHex'] ?? '');
        $remoteSignerPubkeyHex = (string) ($data['remoteSignerPubkeyHex'] ?? $data['bunkerPubkeyHex'] ?? '');
        $relayUrls = $this->sanitizeRelayUrls($data['relayUrls'] ?? $data['bunkerRelays'] ?? []);
        $secret = isset($data['secret']) ? (string) $data['secret'] : null;

        if (!$this->isHexKey($clientPrivkeyHex)) {
            return new JsonResponse(['error' => 'clientPrivkeyHex must be a 64-character hex key'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$this->isHexKey($remoteSignerPubkeyHex)) {
            return new JsonResponse(['error' => 'remoteSignerPubkeyHex must be a 64-character hex key'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($relayUrls === []) {
            return new JsonResponse(['error' => 'At least one bunker relay URL is required'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $session = RemoteSignerSession::forBunker(
            $clientPrivkeyHex,
            $remoteSignerPubkeyHex,
            $relayUrls,
            $subjectPubkeyHex,
            $secret,
        );

        $this->sessions->storeSession($subjectPubkeyHex, $session);
        $this->logger->info('Remote signer session registered', [
            'subject' => substr($subjectPubkeyHex, 0, 8).'...',
            'remote_signer_pubkey' => substr($remoteSignerPubkeyHex, 0, 8).'...',
            'relay_count' => count($relayUrls),
        ]);

        return new JsonResponse([
            'status' => 'ok',
            'method' => 'nip46',
            'subjectPubkeyHex' => $subjectPubkeyHex,
            'remoteSignerPubkeyHex' => $remoteSignerPubkeyHex,
            'relayCount' => count($relayUrls),
        ], JsonResponse::HTTP_CREATED);
    }

    private function isHexKey(string $key): bool
    {
        return strlen($key) === 64 && ctype_xdigit($key);
    }

    /**
     * @param mixed $relayUrls
     * @return list<string>
     */
    private function sanitizeRelayUrls(mixed $relayUrls): array
    {
        if (!is_array($relayUrls)) {
            return [];
        }

        $sanitized = [];
        foreach ($relayUrls as $relayUrl) {
            if (!is_string($relayUrl)) {
                continue;
            }

            $relayUrl = trim($relayUrl);
            if ($relayUrl === '' || strlen($relayUrl) > 512) {
                continue;
            }

            if (!str_starts_with($relayUrl, 'ws://') && !str_starts_with($relayUrl, 'wss://')) {
                continue;
            }

            $sanitized[strtolower($relayUrl)] = $relayUrl;
        }

        return array_values($sanitized);
    }
}
