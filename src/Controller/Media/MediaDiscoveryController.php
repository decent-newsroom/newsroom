<?php

declare(strict_types=1);

namespace App\Controller\Media;

use App\Entity\Event;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\MutedPubkeysService;
use App\Service\Nostr\NostrClient;
use App\Util\NostrKeyUtil;
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

    #[Route('/multimedia', name: 'media-discovery')]
    public function discover(): Response
    {
        $isLoggedIn = $this->getUser() !== null;

        return $this->render('pages/media-discovery.html.twig', [
            'isLoggedIn' => $isLoggedIn,
        ]);
    }

    #[Route('/multimedia/tab/{tab}', name: 'media_discovery_tab', requirements: ['tab' => 'latest|follows|interests|collections'])]
    public function tab(
        string $tab,
        CacheInterface $cache,
        ParameterBagInterface $params,
        EventRepository $eventRepository,
        MutedPubkeysService $mutedPubkeysService,
        NostrClient $nostrClient,
        LoggerInterface $logger,
    ): Response {
        return match ($tab) {
            'latest' => $this->latestTab($cache, $params, $eventRepository, $mutedPubkeysService, $logger),
            'follows' => $this->followsTab($eventRepository, $nostrClient, $mutedPubkeysService, $logger),
            'interests' => $this->interestsTab($eventRepository, $nostrClient, $mutedPubkeysService, $logger),
            'collections' => $this->collectionsTab($eventRepository, $mutedPubkeysService, $logger),
        };
    }

    private function latestTab(
        CacheInterface $cache,
        ParameterBagInterface $params,
        EventRepository $eventRepository,
        MutedPubkeysService $mutedPubkeysService,
        LoggerInterface $logger,
    ): Response {
        try {
            $env = $params->get('kernel.environment');
            $cacheKey = 'media_discovery_events_all_' . $env;

            $allCachedEvents = $cache->get($cacheKey, function () use ($eventRepository, $mutedPubkeysService, $logger) {
                $logger->info('Media discovery cache miss - querying from database');

                $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
                $events = $eventRepository->findNonNSFWMediaEvents(
                    [20, 21, 22],
                    $excludedPubkeys,
                    500
                );

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

            $mediaEvents = $allCachedEvents;
            if (count($mediaEvents) > self::MAX_DISPLAY_EVENTS) {
                shuffle($mediaEvents);
                $mediaEvents = array_slice($mediaEvents, 0, self::MAX_DISPLAY_EVENTS);
            }

            return $this->render('media/tabs/_latest.html.twig', [
                'mediaEvents' => $mediaEvents,
            ]);

        } catch (\Exception $e) {
            $logger->error('Error loading media latest tab', [
                'error' => $e->getMessage(),
            ]);

            return $this->render('media/tabs/_latest.html.twig', [
                'mediaEvents' => [],
                'error' => 'Unable to load media at this time. Please try again later.',
            ]);
        }
    }

    private function followsTab(
        EventRepository $eventRepository,
        NostrClient $nostrClient,
        MutedPubkeysService $mutedPubkeysService,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->render('media/tabs/_follows.html.twig', [
                'mediaEvents' => [],
                'isLoggedIn' => false,
            ]);
        }

        try {
            $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            $logger->error('Failed to convert npub to hex for media follows tab', ['error' => $e->getMessage()]);
            return $this->render('media/tabs/_follows.html.twig', [
                'mediaEvents' => [],
                'isLoggedIn' => true,
                'error' => 'Unable to process credentials.',
            ]);
        }

        // Try media follows (kind 10020) first, fall back to regular follows (kind 3)
        $followedPubkeys = [];
        $isMediaFollows = false;
        try {
            $followedPubkeys = $nostrClient->getUserMediaFollows($pubkeyHex, $user->getRelays()['all'] ?? null);
            if (!empty($followedPubkeys)) {
                $isMediaFollows = true;
            }
        } catch (\Throwable $e) {
            $logger->warning('Failed to fetch media follows, falling back to regular follows', ['error' => $e->getMessage()]);
        }

        // Fallback to regular follows if no media follows list
        if (empty($followedPubkeys)) {
            try {
                $followedPubkeys = $nostrClient->getUserFollows($pubkeyHex, $user->getRelays()['all'] ?? null);
            } catch (\Throwable $e) {
                $logger->error('Failed to fetch follows for media follows tab', ['error' => $e->getMessage()]);
                return $this->render('media/tabs/_follows.html.twig', [
                    'mediaEvents' => [],
                    'isLoggedIn' => true,
                    'error' => 'Unable to fetch your follow list.',
                ]);
            }
        }

        $mediaEvents = [];
        if (!empty($followedPubkeys)) {
            $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
            $filteredPubkeys = array_values(array_diff($followedPubkeys, $excludedPubkeys));

            $events = $eventRepository->findNonNSFWMediaEventsByPubkeys($filteredPubkeys, [20, 21, 22], self::MAX_DISPLAY_EVENTS);

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
        }

        return $this->render('media/tabs/_follows.html.twig', [
            'mediaEvents' => $mediaEvents,
            'isLoggedIn' => true,
            'followCount' => count($followedPubkeys),
            'isMediaFollows' => $isMediaFollows,
        ]);
    }

    private function interestsTab(
        EventRepository $eventRepository,
        NostrClient $nostrClient,
        MutedPubkeysService $mutedPubkeysService,
        LoggerInterface $logger,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->render('media/tabs/_interests.html.twig', [
                'mediaEvents' => [],
                'isLoggedIn' => false,
            ]);
        }

        $interestTags = [];
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
            $interestTags = $nostrClient->getUserInterests($pubkey, $user->getRelays()['all'] ?? null);
        } catch (\Throwable $e) {
            $logger->error('Failed to fetch interests for media interests tab', ['error' => $e->getMessage()]);
        }

        $mediaEvents = [];
        if (!empty($interestTags)) {
            $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
            $events = $eventRepository->findMediaEventsByHashtags($interestTags, [20, 21, 22], $excludedPubkeys, 500);

            // Filter NSFW
            $events = array_filter($events, fn($e) => !$e->isNSFW());

            // Limit and convert
            $events = array_slice($events, 0, self::MAX_DISPLAY_EVENTS);
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
        }

        return $this->render('media/tabs/_interests.html.twig', [
            'mediaEvents' => $mediaEvents,
            'isLoggedIn' => true,
            'interestTags' => $interestTags,
        ]);
    }

    private function collectionsTab(
        EventRepository $eventRepository,
        MutedPubkeysService $mutedPubkeysService,
        LoggerInterface $logger,
    ): Response {
        try {
            $excludedPubkeys = $mutedPubkeysService->getMutedPubkeys();
            $events = $eventRepository->findLatestCurationCollections(
                [KindsEnum::CURATION_VIDEOS->value, KindsEnum::CURATION_PICTURES->value],
                $excludedPubkeys,
                self::MAX_DISPLAY_EVENTS,
            );

            $collections = array_map(
                fn (Event $event) => $this->normalizeCollection($event),
                $events,
            );

            return $this->render('media/tabs/_collections.html.twig', [
                'collections' => $collections,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Error loading media collections tab', [
                'error' => $e->getMessage(),
            ]);

            return $this->render('media/tabs/_collections.html.twig', [
                'collections' => [],
                'error' => 'Unable to load collections at this time. Please try again later.',
            ]);
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    private function normalizeCollection(Event $event): array
    {
        $kind = $event->getKind();

        return [
            'id' => $event->getId(),
            'title' => $event->getTitle() ?: $event->getSlug() ?: '(untitled)',
            'summary' => $event->getSummary(),
            'slug' => (string) $event->getSlug(),
            'pubkey' => $event->getPubkey(),
            'createdAt' => $event->getCreatedAt(),
            'kind' => $kind,
            'itemCount' => $this->countCollectionItems($event),
            'typeKey' => match ($kind) {
                KindsEnum::CURATION_VIDEOS->value => 'media.collections.videos',
                KindsEnum::CURATION_PICTURES->value => 'media.collections.pictures',
                default => 'media.collections.heading',
            },
        ];
    }

    private function countCollectionItems(Event $event): int
    {
        $count = 0;

        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1])) {
                continue;
            }

            if ($tag[0] === 'a' || $tag[0] === 'e') {
                $count++;
            }
        }

        return $count;
    }
}
