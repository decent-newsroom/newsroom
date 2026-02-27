<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshArticleHighlightsMessage;
use App\Service\Nostr\NostrClient;
use App\Repository\HighlightRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Entity\Highlight;

/**
 * Handles async highlight refresh for a specific article coordinate.
 * This runs in the worker process, not during the web request,
 * preventing relay timeouts from causing 502 errors.
 */
#[AsMessageHandler]
class RefreshArticleHighlightsHandler
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly HighlightRepository $highlightRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RefreshArticleHighlightsMessage $message): void
    {
        $coordinate = $message->getArticleCoordinate();
        $isFullRefresh = $message->isFullRefresh();

        $this->logger->info('Async highlight refresh started', [
            'coordinate' => $coordinate,
            'full_refresh' => $isFullRefresh,
        ]);

        try {
            $highlightEvents = $this->nostrClient->getHighlightsForArticle($coordinate);

            if ($isFullRefresh) {
                // Clear old cache for full refresh
                $cutoff = new \DateTimeImmutable('-30 days');
                $this->highlightRepository->deleteOldHighlights($coordinate, $cutoff);
            }

            // Save highlights
            $this->saveHighlights($coordinate, $highlightEvents);

            $this->logger->info('Async highlight refresh completed', [
                'coordinate' => $coordinate,
                'events_fetched' => count($highlightEvents),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Async highlight refresh failed', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function saveHighlights(string $articleCoordinate, array $events): void
    {
        $saved = 0;

        foreach ($events as $event) {
            if (!isset($event->id) || empty($event->id)) {
                continue;
            }

            // Skip duplicates
            $existing = $this->highlightRepository->findOneBy(['eventId' => $event->id]);
            if ($existing) {
                // Update cached_at to mark as refreshed
                $existing->setCachedAt(new \DateTimeImmutable());
                $saved++;
                continue;
            }

            $highlight = new Highlight();
            $highlight->setEventId($event->id);
            $highlight->setArticleCoordinate($articleCoordinate);
            $highlight->setContent($event->content ?? '');
            $highlight->setPubkey($event->pubkey ?? '');
            $highlight->setCreatedAt($event->created_at ?? time());
            $highlight->setCachedAt(new \DateTimeImmutable());

            // Extract context from tags
            $tags = $event->tags ?? [];
            foreach ($tags as $tag) {
                if (is_array($tag) && ($tag[0] ?? '') === 'context' && isset($tag[1])) {
                    $highlight->setContext($tag[1]);
                    break;
                }
            }

            $highlight->setRawEvent((array) $event);

            if (!empty($highlight->getContent())) {
                $this->entityManager->persist($highlight);
                $saved++;
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Highlights saved by async handler', [
            'coordinate' => $articleCoordinate,
            'saved' => $saved,
        ]);
    }
}

