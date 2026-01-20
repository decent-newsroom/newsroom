<?php

declare(strict_types=1);

namespace App\Controller\Media;

use App\Repository\EventRepository;
use App\Service\MutedPubkeysService;
use Psr\Log\LoggerInterface;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;

class MediaDiscoveryController extends AbstractController
{
    private const CACHE_TTL = 10800; // 3 hours in seconds
    private const MAX_DISPLAY_EVENTS = 42;

    // Hardcoded topic to hashtag mapping
    private const TOPIC_HASHTAGS = [
        'photography' => ['photography', 'photo', 'photostr', 'photographer', 'photos', 'picture'],
        'nature' => ['nature', 'landscape', 'wildlife', 'outdoor', 'naturephotography', 'pets', 'catstr', 'dogstr', 'flowers', 'forest', 'mountains', 'beach', 'sunset', 'sunrise'],
        'travel' => ['travel', 'traveling', 'wanderlust', 'adventure', 'explore', 'city', 'vacation', 'trip'],
    ];

    #[Route('/multimedia', name: 'media-discovery')]
    public function discover(
        CacheInterface $cache,
        ParameterBagInterface $params,
        EventRepository $eventRepository,
        MutedPubkeysService $mutedPubkeysService,
        LoggerInterface $logger
    ): Response {
        try {
            $allHashtags = [];
            // Get all topics
            foreach (array_keys(self::TOPIC_HASHTAGS) as $topic) {
                $allHashtags = array_merge($allHashtags, self::TOPIC_HASHTAGS[$topic]);
            }

            // Cache key for all media events
            $env = $params->get('kernel.environment');
            $cacheKey = 'media_discovery_events_all_' . $env;

            // Try to get from cache first
            $allCachedEvents = $cache->get($cacheKey, function () use ($eventRepository, $mutedPubkeysService, $logger) {
                $logger->info('Media discovery cache miss - querying from database');

                // Fallback: query from database if cache is not populated
                $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
                $events = $eventRepository->findNonNSFWMediaEvents(
                    [20, 21, 22],
                    $excludedPubkeys,
                    500
                );

                // Convert Event entities to simple objects for display
                $mediaEvents = [];
                $nip19 = new Nip19Helper();

                foreach ($events as $event) {
                    $obj = new \stdClass();
                    $obj->id = $event->getId();
                    $obj->pubkey = $event->getPubkey();
                    $obj->created_at = $event->getCreatedAt();
                    $obj->kind = $event->getKind();
                    $obj->tags = $event->getTags();
                    $obj->content = $event->getContent();
                    $obj->sig = $event->getSig();
                    $obj->noteId = $nip19->encodeNote($event->getId());

                    $mediaEvents[] = $obj;
                }

                $logger->info('Media discovery queried from database', [
                    'event_count' => count($mediaEvents)
                ]);

                return $mediaEvents;
            });

            // Randomize from the cached events
            $mediaEvents = $allCachedEvents;
            if (count($mediaEvents) > self::MAX_DISPLAY_EVENTS) {
                shuffle($mediaEvents);
                $mediaEvents = array_slice($mediaEvents, 0, self::MAX_DISPLAY_EVENTS);
            }

            return $this->render('pages/media-discovery.html.twig', [
                'mediaEvents' => $mediaEvents,
                'total' => count($mediaEvents),
                'topics' => array_keys(self::TOPIC_HASHTAGS),
                'selectedTopic' => $topic ?? null,
            ]);

        } catch (\Exception $e) {
            // Log error and show empty state
            $logger->error('Error loading media discovery', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->render('pages/media-discovery.html.twig', [
                'mediaEvents' => [],
                'total' => 0,
                'topics' => array_keys(self::TOPIC_HASHTAGS),
                'selectedTopic' => $topic ?? null,
                'error' => 'Unable to load media at this time. Please try again later.',
            ]);
        }
    }
}
