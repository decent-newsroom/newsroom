<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\KindsEnum;
use App\Entity\Event as EventEntity;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api')]
final class PublicationBroadcastController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
        private readonly UserRelayListService $userRelayListService,
        private readonly RelayRegistry $relayRegistry,
        private readonly string $essayistRelayPublicUrl = '',
        private readonly string $essayistRelayInternalUrl = '',
    ) {
    }

    private function normaliseRelayUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'wss');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (($scheme === 'wss' && $port === 443) || ($scheme === 'ws' && $port === 80)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    private function remapEssayistRelays(array $relays): array
    {
        if ($this->essayistRelayInternalUrl === '' || $this->essayistRelayPublicUrl === '') {
            return $relays;
        }

        $user = $this->getUser();
        if (!$user || !in_array('ROLE_ESSAYIST_MEMBER', $user->getRoles(), true)) {
            return $relays;
        }

        $publicNormalised = $this->normaliseRelayUrl($this->essayistRelayPublicUrl);
        $remapped = false;
        $out = [];

        foreach ($relays as $url) {
            if ($this->normaliseRelayUrl($url) === $publicNormalised) {
                $out[] = $this->essayistRelayInternalUrl;
                $remapped = true;
                continue;
            }

            $out[] = $url;
        }

        if ($remapped) {
            $this->logger->info('Remapped Essayist public URL to internal relay for member publication broadcast', [
                'public' => $this->essayistRelayPublicUrl,
                'internal' => $this->essayistRelayInternalUrl,
                'pubkey' => substr($user->getUserIdentifier(), 0, 12) . '...',
            ]);
        }

        return $out;
    }

    private function filterProjectRelaysForBroadcast(array $relays): array
    {
        $local = $this->relayRegistry->getLocalRelay();
        if ($local === null) {
            return $relays;
        }

        $filtered = [];
        foreach ($relays as $relay) {
            if (!$this->relayRegistry->isProjectRelay($relay)) {
                $filtered[] = $relay;
            }
        }

        if (!in_array($local, $filtered, true)) {
            $filtered[] = $local;
        }

        return array_values(array_unique($filtered));
    }

    private function toRawEventPayload(EventEntity $event): array
    {
        return [
            'id' => $event->getId(),
            'pubkey' => $event->getPubkey(),
            'created_at' => $event->getCreatedAt(),
            'kind' => $event->getKind(),
            'tags' => $event->getTags(),
            'content' => $event->getContent(),
            'sig' => $event->getSig(),
        ];
    }

    private function resolvePublication(?string $eventId, ?string $coordinate): ?EventEntity
    {
        if (is_string($eventId) && $eventId !== '') {
            $event = $this->eventRepository->findById($eventId);
            if ($event instanceof EventEntity && $event->getKind() === KindsEnum::PUBLICATION_INDEX->value) {
                return $event;
            }
        }

        if (!is_string($coordinate) || $coordinate === '') {
            return null;
        }

        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$kindRaw, $pubkey, $slug] = $parts;
        if ((int) $kindRaw !== KindsEnum::PUBLICATION_INDEX->value || $pubkey === '' || $slug === '') {
            return null;
        }

        return $this->eventRepository->findByNaddr(KindsEnum::PUBLICATION_INDEX->value, $pubkey, $slug);
    }

    #[Route('/broadcast-publication', name: 'api_broadcast_publication', methods: ['POST'])]
    public function broadcastPublication(Request $request): JsonResponse
    {
        set_time_limit(60);

        try {
            $data = json_decode($request->getContent(), true);
            if (!is_array($data)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid request data',
                ], 400);
            }

            $eventId = $data['event_id'] ?? null;
            $coordinate = $data['coordinate'] ?? null;
            $relays = $data['relays'] ?? [];

            if (!is_array($relays) || $relays === []) {
                $user = $this->getUser();
                if (!$user) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Authentication required to broadcast publications',
                    ], 401);
                }

                try {
                    $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                    $relays = $this->userRelayListService->getRelaysForPublishing($pubkeyHex);
                } catch (Throwable $e) {
                    $this->logger->warning('Failed to resolve user relays for publication broadcast, using fallback relays', [
                        'error' => $e->getMessage(),
                    ]);
                    $relays = $this->userRelayListService->getFallbackRelays();
                }
            }

            $relays = $this->remapEssayistRelays($relays);
            $relays = $this->filterProjectRelaysForBroadcast($relays);

            $publication = $this->resolvePublication(
                is_string($eventId) ? $eventId : null,
                is_string($coordinate) ? $coordinate : null,
            );

            if (!$publication) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Publication not found',
                    'event_id' => $eventId,
                    'coordinate' => $coordinate,
                ], 404);
            }

            try {
                $event = Event::fromVerified((object) $this->toRawEventPayload($publication));
            } catch (Throwable $verificationError) {
                $this->logger->warning('Publication broadcast rejected: event verification failed', [
                    'event_id' => $publication->getId(),
                    'error' => $verificationError->getMessage(),
                ]);

                return new JsonResponse([
                    'success' => false,
                    'error' => 'Publication event failed verification and was not broadcast.',
                    'reason' => 'Event verification failed for stored publication payload',
                    'event_id' => $publication->getId(),
                ], 422);
            }

            if (!$event instanceof Event) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Publication event failed verification and was not broadcast.',
                    'reason' => 'Stored publication payload could not be verified as a signed event',
                    'event_id' => $publication->getId(),
                ], 422);
            }

            $this->logger->info('Broadcasting publication to relays', [
                'event_id' => $publication->getId(),
                'slug' => $publication->getSlug(),
                'pubkey' => substr($publication->getPubkey(), 0, 8) . '...',
                'relay_count' => count($relays),
            ]);

            $results = $this->nostrClient->publishEvent($event, $relays, 10, ensureLocalRelay: false);

            $successCount = 0;
            $failedRelays = [];

            foreach ($results as $relay => $result) {
                $success = false;
                $message = '';

                if (is_object($result)) {
                    $success = (bool) ($result->isSuccess ?? $result->status ?? false);
                    $message = (string) ($result->message ?? '');
                } elseif (is_array($result)) {
                    $success = (bool) ($result['ok'] ?? false);
                    $message = (string) ($result['message'] ?? '');
                }

                if ($success) {
                    $successCount++;
                    continue;
                }

                $failedRelays[] = [
                    'relay' => $relay,
                    'error' => $message !== '' ? $message : 'No confirmation received',
                ];
            }

            $totalRelays = count($results);
            $allFailed = $totalRelays > 0 && $successCount === 0;

            return new JsonResponse([
                'success' => !$allFailed,
                'message' => $allFailed
                    ? 'Publication broadcast failed on all relays'
                    : 'Publication broadcast to relays',
                'error' => $allFailed
                    ? ($failedRelays[0]['error'] ?? 'All relays rejected the event')
                    : null,
                'publication' => [
                    'event_id' => $publication->getId(),
                    'slug' => $publication->getSlug(),
                    'title' => $publication->getTitle(),
                    'kind' => $publication->getKind(),
                    'pubkey' => $publication->getPubkey(),
                ],
                'broadcast' => [
                    'total_relays' => $totalRelays,
                    'successful' => $successCount,
                    'failed' => count($failedRelays),
                    'failed_relays' => $failedRelays,
                ],
            ], $allFailed ? 502 : 200);
        } catch (Throwable $e) {
            $this->logger->error('Failed to broadcast publication', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to broadcast publication: ' . $e->getMessage(),
            ], 500);
        }
    }
}


