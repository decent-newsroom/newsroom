<?php

namespace App\Controller\Api;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Nostr\NostrClient;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ChapterFetchController extends AbstractController
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
    ) {}

    /**
     * Endpoint to fetch a chapter (30041) that hasn't been loaded yet
     * This is called when user clicks "Fetch Chapter" button
     */
    #[Route('/fetch-chapter', name: 'api_fetch_chapter', methods: ['POST'])]
    public function fetchChapter(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid request data'
            ], 400);
        }

        $coordinate = $data['coordinate'] ?? null;
        $mag = $data['mag'] ?? null;

        if (!$coordinate) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required parameter: coordinate'
            ], 400);
        }

        // Validate coordinate format
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid coordinate format. Expected: kind:pubkey:slug'
            ], 400);
        }

        $kind = (int)$parts[0];
        if ($kind !== KindsEnum::PUBLICATION_CONTENT->value) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid kind. Expected 30041 (PUBLICATION_CONTENT)'
            ], 400);
        }

        try {
            $this->logger->info('Fetching chapter from relays', [
                'coordinate' => $coordinate
            ]);

            // Fetch from Nostr relays using existing method
            $eventsMap = $this->nostrClient->getArticlesByCoordinates([$coordinate]);

            if (empty($eventsMap)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Chapter not found on Nostr relays',
                    'coordinate' => $coordinate
                ], 404);
            }

            // Get the fetched event
            $nostrEvent = $eventsMap[$coordinate] ?? null;

            if (!$nostrEvent) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Chapter not found in response',
                    'coordinate' => $coordinate
                ], 404);
            }

            $alreadyExists = $this->entityManager->find(Event::class, $nostrEvent->id) instanceof Event;

            if (!$alreadyExists) {
                $event = new Event();
                $event->setId($nostrEvent->id);
                $event->setEventId($nostrEvent->id);
                $event->setKind($nostrEvent->kind);
                $event->setPubkey($nostrEvent->pubkey);
                $event->setContent($nostrEvent->content);
                $event->setCreatedAt($nostrEvent->created_at);
                $event->setTags($nostrEvent->tags);
                $event->setSig($nostrEvent->sig);

                try {
                    $this->entityManager->persist($event);
                    $this->entityManager->flush();
                } catch (UniqueConstraintViolationException) {
                    // Race-safe idempotency: if another request saved first, treat as success.
                    $alreadyExists = true;
                }
            }

            $this->invalidateMagazineChaptersCache($mag);

            $this->logger->info('Chapter fetch completed', [
                'coordinate' => $coordinate,
                'event_id' => $nostrEvent->id,
                'already_exists' => $alreadyExists,
            ]);

            return new JsonResponse([
                'success' => true,
                'message' => $alreadyExists
                    ? 'Chapter already exists locally; cache refreshed.'
                    : 'Chapter successfully fetched from Nostr and saved to database.',
                'coordinate' => $coordinate,
                'event_id' => $nostrEvent->id,
                'already_exists' => $alreadyExists,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch chapter', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to fetch chapter: ' . $e->getMessage(),
                'coordinate' => $coordinate
            ], 500);
        }
    }

    private function invalidateMagazineChaptersCache(mixed $mag): void
    {
        if (!is_string($mag) || $mag === '') {
            return;
        }

        $this->cache->deleteItem('magazine_chapters_frame_' . $mag);
    }
}
