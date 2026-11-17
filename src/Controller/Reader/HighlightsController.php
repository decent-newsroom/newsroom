<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Message\FetchHighlightsMessage;
use App\Service\HighlightFeedService;
use App\Service\Reader\HighlightSourceVisibility;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class HighlightsController extends AbstractController
{
    private const MAX_DISPLAY_HIGHLIGHTS = 200;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly HighlightFeedService $highlightFeedService,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/highlights', name: 'highlights')]
    public function index(HighlightSourceVisibility $highlightSources): Response
    {
        try {
            $feed = $this->highlightFeedService->loadLatestHighlights(self::MAX_DISPLAY_HIGHLIGHTS);
            $highlights = $highlightSources->filter($feed['highlights']);
            $fromRedisView = $feed['from_redis_view'];

            if ($fromRedisView) {
                $this->logger->info('Loaded highlights from Redis view', ['count' => count($highlights)]);
            }

            // Warm refresh when we are in fallback mode.
            if (!$fromRedisView) {
                try {
                    $this->messageBus->dispatch(new FetchHighlightsMessage(self::MAX_DISPLAY_HIGHLIGHTS));
                    $this->logger->debug('Dispatched async fetch for highlights');
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to dispatch highlights fetch', ['error' => $e->getMessage()]);
                }
            }

            $response = $this->render('pages/highlights.html.twig', [
                'highlights' => $highlights,
                'total' => count($highlights),
                'from_redis_view' => $fromRedisView,
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Error loading highlights page', [
                'error' => $e->getMessage()
            ]);

            $response = $this->render('pages/highlights.html.twig', [
                'highlights' => [],
                'total' => 0,
                'error' => 'Unable to load highlights at this time. Please try again later.',
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        }
    }
}
