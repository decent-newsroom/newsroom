<?php

declare(strict_types=1);

namespace App\Controller;

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
    public function discover(CacheInterface $cache, ParameterBagInterface $params): Response
    {
        // Defaulting to all, might do topics later
        try {
            $allHashtags = [];
            // Get all topics
            foreach (array_keys(self::TOPIC_HASHTAGS) as $topic) {
                $allHashtags = array_merge($allHashtags, self::TOPIC_HASHTAGS[$topic]);
            }

            // Cache key for all media events
            $env = $params->get('kernel.environment');
            $cacheKey = 'media_discovery_events_all_' . $env;

            // Read from cache only - the cache is populated by the CacheMediaDiscoveryCommand
            $allCachedEvents = $cache->get($cacheKey, function () {
                // Return empty array if cache is not populated yet
                // The command should be run to populate this
                return [];
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
